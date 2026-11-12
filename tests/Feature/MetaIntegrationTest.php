<?php

namespace Tests\Feature;

use App\Enums\MetaConnectionStatus;
use App\Enums\WorkspaceRole;
use App\Models\MetaConnection;
use App\Models\MetaOauthState;
use App\Models\MetaPage;
use App\Models\Store;
use App\Models\User;
use App\Support\Meta\Contracts\MetaProvider;
use App\Support\Meta\FakeMetaProvider;
use App\Support\Meta\GraphMetaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class MetaIntegrationTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_meta_driver_defaults_to_fake(): void
    {
        $this->assertSame('fake', config('services.meta.driver'));
        $this->assertInstanceOf(FakeMetaProvider::class, app(MetaProvider::class));
    }

    public function test_admin_can_start_oauth_and_state_is_hashed(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $response = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringContainsString('code='.FakeMetaProvider::FAKE_AUTH_CODE, $location);
        $this->assertStringContainsString('state=', $location);

        $this->assertDatabaseCount('meta_oauth_states', 1);
        $row = MetaOauthState::query()->first();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame($store->id, $row->store_id);
        $this->assertSame(64, strlen($row->state_hash));
        $this->assertNull($row->consumed_at);

        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame($row->state_hash, MetaOauthState::hashState($query['state']));
        $this->assertNotSame($query['state'], $row->state_hash);
    }

    public function test_marketer_cannot_start_or_disconnect_meta(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $marketer = $this->marketerIn($workspace);
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($marketer)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start")
            ->assertForbidden();

        $connection = new MetaConnection([
            'store_id' => $store->id,
            'meta_user_id' => 'u1',
            'status' => MetaConnectionStatus::Connected,
            'connected_at' => now(),
        ]);
        $connection->user_access_token = 'tok';
        $connection->save();

        $this->spa()->actingAs($marketer)
            ->deleteJson("/api/v1/stores/{$store->id}/meta/connection")
            ->assertForbidden();
    }

    public function test_outsider_cannot_connect_another_workspace_store(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$outsider] = $this->adminWithWorkspace('Other');

        $this->spa()->actingAs($outsider)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start")
            ->assertForbidden();
    }

    public function test_oauth_callback_persists_encrypted_connection_and_rejects_replay(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $start = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start");
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);
        $state = $query['state'];

        $callback = $this->get('/api/v1/meta/oauth/callback?'.http_build_query([
            'code' => FakeMetaProvider::FAKE_AUTH_CODE,
            'state' => $state,
        ]));
        $callback->assertRedirect();
        $this->assertStringContainsString('meta=connected', $callback->headers->get('Location'));

        $connection = MetaConnection::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame(MetaConnectionStatus::Connected, $connection->status);
        $this->assertSame(FakeMetaProvider::FAKE_USER_ID, $connection->meta_user_id);
        $this->assertSame('fake-meta-user-token', $connection->user_access_token);

        $raw = $connection->getAttributes()['user_access_token'] ?? null;
        $this->assertIsString($raw);
        $this->assertNotSame('fake-meta-user-token', $raw);
        $this->assertSame('fake-meta-user-token', Crypt::decryptString($raw));

        // Replay rejected.
        $replay = $this->get('/api/v1/meta/oauth/callback?'.http_build_query([
            'code' => FakeMetaProvider::FAKE_AUTH_CODE,
            'state' => $state,
        ]));
        $this->assertStringContainsString('reason=state_replay', $replay->headers->get('Location'));
    }

    public function test_expired_and_unknown_state_are_rejected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        [$plaintext, $hash] = MetaOauthState::issuePlaintextAndHash();
        MetaOauthState::query()->create([
            'state_hash' => $hash,
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'expires_at' => now()->subMinute(),
        ]);

        $expired = $this->get('/api/v1/meta/oauth/callback?'.http_build_query([
            'code' => FakeMetaProvider::FAKE_AUTH_CODE,
            'state' => $plaintext,
        ]));
        $this->assertStringContainsString('reason=state_expired', $expired->headers->get('Location'));

        $unknown = $this->get('/api/v1/meta/oauth/callback?'.http_build_query([
            'code' => FakeMetaProvider::FAKE_AUTH_CODE,
            'state' => 'not-a-real-state-value-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]));
        $this->assertStringContainsString('reason=invalid_state', $unknown->headers->get('Location'));
    }

    public function test_connection_resource_never_exposes_tokens(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectStore($admin, $store);

        $payload = $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/meta/connection")
            ->assertOk()
            ->json();

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('fake-meta-user-token', $encoded);
        $this->assertStringNotContainsString('fake-page-token', $encoded);
        $this->assertStringNotContainsString('user_access_token', $encoded);
        $this->assertStringNotContainsString('page_access_token', $encoded);
        $this->assertTrue($payload['data']['connected']);
    }

    public function test_discover_select_default_and_remove_pages_without_persisting_unselected_tokens(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $marketer = $this->marketerIn($workspace);
        $store = $this->storeIn($workspace);
        $this->connectStore($admin, $store);

        $discovered = $this->spa()->actingAs($marketer)
            ->getJson("/api/v1/stores/{$store->id}/meta/pages/discover")
            ->assertOk()
            ->json('data.pages');

        $this->assertCount(3, $discovered);
        $this->assertArrayNotHasKey('access_token', $discovered[0]);
        $this->assertStringContainsString('[Simulated]', $discovered[0]['name']);

        $this->spa()->actingAs($marketer)->postJson("/api/v1/stores/{$store->id}/meta/pages/select", [
            'page_ids' => ['fake-page-100', 'fake-page-200'],
            'default_page_id' => 'fake-page-200',
        ])->assertOk();

        $this->assertDatabaseCount('meta_pages', 2);
        $this->assertDatabaseMissing('meta_pages', ['meta_page_id' => 'fake-page-300']);

        $pageA = MetaPage::query()->where('meta_page_id', 'fake-page-100')->first();
        $pageB = MetaPage::query()->where('meta_page_id', 'fake-page-200')->first();
        $this->assertFalse($pageA->is_default);
        $this->assertTrue($pageB->is_default);
        $this->assertSame('fake-page-token-100', $pageA->page_access_token);

        $raw = $pageA->getAttributes()['page_access_token'];
        $this->assertNotSame('fake-page-token-100', $raw);

        $this->spa()->actingAs($marketer)
            ->putJson("/api/v1/stores/{$store->id}/meta/pages/{$pageA->id}/default")
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->assertTrue($pageA->fresh()->is_default);
        $this->assertFalse($pageB->fresh()->is_default);

        $this->spa()->actingAs($marketer)
            ->deleteJson("/api/v1/stores/{$store->id}/meta/pages/{$pageA->id}")
            ->assertOk();

        $this->assertDatabaseMissing('meta_pages', ['id' => $pageA->id]);
        $this->assertTrue($pageB->fresh()->is_default);
    }

    public function test_cross_store_page_mutation_is_rejected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $storeA = $this->storeIn($workspace);
        $storeB = $this->storeIn($workspace);
        $this->connectStore($admin, $storeA);
        $this->connectStore($admin, $storeB);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$storeA->id}/meta/pages/select", [
            'page_ids' => ['fake-page-100'],
        ])->assertOk();

        $page = MetaPage::query()->where('store_id', $storeA->id)->first();

        $this->spa()->actingAs($admin)
            ->putJson("/api/v1/stores/{$storeB->id}/meta/pages/{$page->id}/default")
            ->assertNotFound();

        $this->spa()->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$storeB->id}/meta/pages/{$page->id}")
            ->assertNotFound();
    }

    public function test_missing_meta_configuration_fails_safely_for_real_driver(): void
    {
        config([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => '',
            'services.meta.app_secret' => '',
            'services.meta.redirect_uri' => '',
        ]);
        $this->app->forgetInstance(MetaProvider::class);
        $this->app->singleton(MetaProvider::class, fn () => new GraphMetaProvider);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meta');
    }

    public function test_graph_provider_exchanges_tokens_via_http_fakes(): void
    {
        config([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => 'app-123',
            'services.meta.app_secret' => 'secret-123',
            'services.meta.redirect_uri' => 'https://example.test/api/v1/meta/oauth/callback',
            'services.meta.graph_version' => 'v25.0',
        ]);
        $this->app->forgetInstance(MetaProvider::class);
        $this->app->singleton(MetaProvider::class, fn () => new GraphMetaProvider);

        Http::fake([
            'https://graph.facebook.com/v25.0/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'short-token', 'expires_in' => 3600, 'token_type' => 'bearer'], 200)
                ->push(['access_token' => 'long-token', 'expires_in' => 5184000, 'token_type' => 'bearer'], 200),
            'https://graph.facebook.com/v25.0/me?*' => Http::response(['id' => 'user-9', 'name' => 'Ada'], 200),
            'https://graph.facebook.com/v25.0/me/accounts*' => Http::response([
                'data' => [[
                    'id' => 'page-9',
                    'name' => 'Ada Page',
                    'access_token' => 'page-token-9',
                    'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                ]],
            ], 200),
        ]);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $start = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start");
        $start->assertRedirect();
        $this->assertStringStartsWith('https://www.facebook.com/v25.0/dialog/oauth?', $start->headers->get('Location'));

        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY) ?: '', $authQuery);
        $state = $authQuery['state'];

        $this->get('/api/v1/meta/oauth/callback?'.http_build_query([
            'code' => 'real-auth-code',
            'state' => $state,
        ]))->assertRedirect();

        $connection = MetaConnection::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame('long-token', $connection->user_access_token);
        $this->assertSame('user-9', $connection->meta_user_id);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/meta/pages/select", [
            'page_ids' => ['page-9'],
        ])->assertOk();

        $page = MetaPage::query()->where('store_id', $store->id)->first();
        $this->assertSame('page-token-9', $page->page_access_token);
        $this->assertTrue($page->is_default);
    }

    public function test_invalid_token_marks_needs_reauth(): void
    {
        config([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => 'app-123',
            'services.meta.app_secret' => 'secret-123',
            'services.meta.redirect_uri' => 'https://example.test/api/v1/meta/oauth/callback',
            'services.meta.graph_version' => 'v25.0',
        ]);
        $this->app->forgetInstance(MetaProvider::class);
        $this->app->singleton(MetaProvider::class, fn () => new GraphMetaProvider);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $connection = new MetaConnection([
            'store_id' => $store->id,
            'meta_user_id' => 'user-1',
            'status' => MetaConnectionStatus::Connected,
            'connected_at' => now(),
        ]);
        $connection->user_access_token = 'expired-token';
        $connection->save();

        Http::fake([
            'https://graph.facebook.com/v25.0/me/accounts*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190],
            ], 400),
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/meta/pages/discover")
            ->assertUnprocessable();

        $this->assertSame(MetaConnectionStatus::NeedsReauth, $connection->fresh()->status);
    }

    public function test_oauth_start_is_rate_limited(): void
    {
        RateLimiter::clear('meta-oauth');
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        for ($i = 0; $i < 10; $i++) {
            $this->spa()->actingAs($admin)
                ->get("/api/v1/stores/{$store->id}/meta/oauth/start")
                ->assertRedirect();
        }

        $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start")
            ->assertStatus(429);
    }

    public function test_disconnect_clears_connection_and_pages(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectStore($admin, $store);
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/meta/pages/select", [
            'page_ids' => ['fake-page-100'],
        ])->assertOk();

        $this->spa()->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/meta/connection")
            ->assertOk()
            ->assertJsonPath('data.disconnected', true);

        $this->assertDatabaseMissing('meta_connections', ['store_id' => $store->id]);
        $this->assertDatabaseCount('meta_pages', 0);
    }

    private function connectStore(User $admin, Store $store): void
    {
        $start = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start");
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        $this->get('/api/v1/meta/oauth/callback?'.http_build_query([
            'code' => FakeMetaProvider::FAKE_AUTH_CODE,
            'state' => $query['state'],
        ]))->assertRedirect();
    }
}
