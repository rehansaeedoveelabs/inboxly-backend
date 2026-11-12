<?php

namespace Tests\Feature;

use App\Enums\MetaConnectionStatus;
use App\Enums\MetaPublicationStatus;
use App\Models\Campaign;
use App\Models\CampaignMetaPublication;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Models\User;
use App\Support\Meta\FakeMetaProvider;
use App\Support\Meta\MetaUtm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class MetaPublicationTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMetaProvider::resetPublishedFeed();
        config(['services.meta.allow_fake_publish' => true]);
    }

    public function test_utm_append_preserves_query_and_fragment(): void
    {
        $url = MetaUtm::append('https://example.com/path?x=1#frag', [
            'utm_source' => 'facebook',
            'utm_medium' => 'social',
            'x' => 'should-not-overwrite',
        ]);

        $this->assertStringContainsString('x=1', $url);
        $this->assertStringContainsString('utm_source=facebook', $url);
        $this->assertStringContainsString('utm_medium=social', $url);
        $this->assertStringEndsWith('#frag', $url);
        $this->assertStringNotContainsString('should-not-overwrite', $url);
    }

    public function test_is_usable_respects_user_token_expiry(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectAndSelect($admin, $store);

        $connection = MetaConnection::query()->where('store_id', $store->id)->first();
        $this->assertTrue($connection->isUsable());

        $connection->forceFill(['user_token_expires_at' => now()->subMinute()])->save();
        $this->assertFalse($connection->fresh()->isUsable());
    }

    public function test_select_rejects_pages_without_create_content(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectStore($admin, $store);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/meta/pages/select", [
            'page_ids' => ['fake-page-300'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('page_ids');
    }

    public function test_publish_happy_path_is_simulated_and_independent_of_email(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Hello Facebook',
            'link' => 'https://shop.example.com/sale?ref=1#top',
        ])->assertOk()
            ->assertJsonPath('data.meta_message', 'Hello Facebook');

        $response = $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertStatus(202);

        $publicationId = $response->json('data.0.id');
        $publication = CampaignMetaPublication::query()->find($publicationId);
        $this->assertSame(MetaPublicationStatus::Published, $publication->status);
        $this->assertTrue($publication->simulated);
        $this->assertStringStartsWith('simulated_post_', (string) $publication->provider_post_id);
        $this->assertStringContainsString('utm_source=facebook', (string) $publication->link_snapshot);
        $this->assertStringContainsString('ref=1', (string) $publication->link_snapshot);
        $this->assertStringContainsString('#top', (string) $publication->link_snapshot);

        // Email campaign status untouched.
        $this->assertSame('draft', $campaign->fresh()->status->value);
    }

    public function test_duplicate_content_is_blocked(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Same content',
        ])->assertOk();

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertStatus(202);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertUnprocessable();
    }

    public function test_cross_store_publication_access_is_denied(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $storeA = $this->storeIn($workspace);
        $storeB = $this->storeIn($workspace);
        [$campaignA, $pageA] = $this->readyCampaign($admin, $storeA);
        [$campaignB] = $this->readyCampaign($admin, $storeB);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaignA->id}/meta/social-content", [
            'message' => 'Cross store',
        ])->assertOk();

        $pubId = $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignA->id}/meta/publications", [
            'meta_page_ids' => [$pageA->id],
        ])->assertStatus(202)->json('data.0.id');

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaignB->id}/meta/publications/{$pubId}/retry")
            ->assertNotFound();
    }

    public function test_expired_user_token_blocks_publish(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        MetaConnection::query()->where('store_id', $store->id)->update([
            'user_token_expires_at' => now()->subHour(),
        ]);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Expired token',
        ])->assertOk();

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('meta');
    }

    public function test_resources_never_expose_tokens(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Token leak check',
        ])->assertOk();

        $payload = $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertStatus(202)->json();

        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('fake-page-token', $encoded);
        $this->assertStringNotContainsString('page_access_token', $encoded);
        $this->assertStringNotContainsString('user_access_token', $encoded);
    }

    public function test_reconcile_recovers_unknown_outcome(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Reconcile me',
        ])->assertOk();

        // Seed a simulated feed post, then create an unknown publication matching it.
        FakeMetaProvider::resetPublishedFeed();
        $provider = new FakeMetaProvider;
        $result = $provider->publishPagePost(new \App\Support\Meta\MetaPublishRequest(
            pageId: 'fake-page-100',
            pageAccessToken: 'fake-page-token-100',
            message: 'Reconcile me',
        ));

        $publication = CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::OutcomeUnknown,
            'simulated' => true,
            'idempotency_key' => 'reconcile-key-1',
            'content_hash' => hash('sha256', 'Reconcile me||'),
            'message_snapshot' => 'Reconcile me',
            'attempts' => 1,
            'publishing_started_at' => now()->subMinute(),
            'queued_at' => now()->subMinutes(2),
            'error_code' => 'ambiguous_outcome',
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications/{$publication->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.provider_post_id', $result->providerPostId);
    }

    public function test_retry_blocked_for_outcome_unknown(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $publication = CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::OutcomeUnknown,
            'simulated' => true,
            'idempotency_key' => 'unknown-key-1',
            'content_hash' => hash('sha256', 'x'),
            'message_snapshot' => 'x',
            'attempts' => 1,
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications/{$publication->id}/retry")
            ->assertUnprocessable();
    }

    public function test_outsider_cannot_publish(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);
        [$outsider] = $this->adminWithWorkspace('Other');

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Nope',
        ])->assertOk();

        $this->spa()->actingAs($outsider)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertForbidden();
    }

    public function test_graph_publish_uses_http_fake(): void
    {
        config([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => 'app',
            'services.meta.app_secret' => 'secret',
            'services.meta.redirect_uri' => 'https://example.test/api/v1/meta/oauth/callback',
            'services.meta.graph_version' => 'v25.0',
            'services.meta.allow_fake_publish' => false,
        ]);
        $this->app->forgetInstance(\App\Support\Meta\Contracts\MetaProvider::class);
        $this->app->singleton(
            \App\Support\Meta\Contracts\MetaProvider::class,
            fn () => new \App\Support\Meta\GraphMetaProvider,
        );

        Http::fake([
            'https://graph.facebook.com/v25.0/me/accounts*' => Http::response([
                'data' => [[
                    'id' => 'page-9',
                    'name' => 'Ada Page',
                    'access_token' => 'fresh-page-token',
                    'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                ]],
            ], 200),
            'https://graph.facebook.com/v25.0/page-9/feed' => Http::response([
                'id' => 'page-9_999',
            ], 200),
        ]);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $connection = new MetaConnection([
            'store_id' => $store->id,
            'meta_user_id' => 'user-9',
            'status' => MetaConnectionStatus::Connected,
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
            'connected_at' => now(),
            'user_token_expires_at' => now()->addDays(30),
        ]);
        $connection->user_access_token = 'long-token';
        $connection->save();

        $page = new MetaPage([
            'store_id' => $store->id,
            'meta_connection_id' => $connection->id,
            'meta_page_id' => 'page-9',
            'name' => 'Ada Page',
            'tasks' => ['CREATE_CONTENT'],
            'is_default' => true,
            'selected_at' => now(),
        ]);
        $page->page_access_token = 'stale-page-token';
        $page->save();

        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => 'draft',
            'meta_message' => 'Graph publish',
        ]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertStatus(202)
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.provider_post_id', 'page-9_999')
            ->assertJsonPath('data.0.simulated', false);

        $this->assertSame('fresh-page-token', $page->fresh()->page_access_token);
    }

    /** @return array{0: Campaign, 1: MetaPage} */
    private function readyCampaign(User $admin, $store): array
    {
        $this->connectAndSelect($admin, $store);
        $page = MetaPage::query()->where('store_id', $store->id)->where('meta_page_id', 'fake-page-100')->firstOrFail();
        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => 'draft',
            'subject' => 'Email subject',
            'body' => 'Email body',
        ]);

        return [$campaign, $page];
    }

    private function connectAndSelect(User $admin, $store): void
    {
        $this->connectStore($admin, $store);
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/meta/pages/select", [
            'page_ids' => ['fake-page-100', 'fake-page-200'],
            'default_page_id' => 'fake-page-100',
        ])->assertOk();
    }

    private function connectStore(User $admin, $store): void
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
