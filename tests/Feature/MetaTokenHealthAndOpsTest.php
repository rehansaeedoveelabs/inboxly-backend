<?php

namespace Tests\Feature;

use App\Enums\MetaConnectionStatus;
use App\Enums\MetaPublicationStatus;
use App\Jobs\ProcessMetaPublicationJob;
use App\Models\Campaign;
use App\Models\CampaignMetaPublication;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Support\Meta\Contracts\MetaProvider;
use App\Support\Meta\FakeMetaProvider;
use App\Support\Meta\GraphMetaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class MetaTokenHealthAndOpsTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMetaProvider::resetPublishedFeed();
        config(['services.meta.allow_fake_publish' => true]);
    }

    public function test_data_access_expiry_from_debug_token_is_persisted(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectStore($admin, $store);

        $connection = MetaConnection::query()->where('store_id', $store->id)->firstOrFail();
        $this->assertNotNull($connection->data_access_expires_at);
        $this->assertTrue($connection->data_access_expires_at->greaterThan(now()->addDays(30)));
        $this->assertTrue($connection->isUsable());
    }

    public function test_missing_data_access_expiry_does_not_invent_expiration(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectStore($admin, $store);

        $connection = MetaConnection::query()->where('store_id', $store->id)->firstOrFail();
        $connection->forceFill([
            'data_access_expires_at' => null,
            'user_token_expires_at' => now()->addDays(10),
            'status' => MetaConnectionStatus::Connected,
        ])->save();

        $this->assertNull($connection->fresh()->unusableReason());
        $this->assertTrue($connection->fresh()->isUsable());
    }

    public function test_expired_data_access_blocks_publish_before_graph_post(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        MetaConnection::query()->where('store_id', $store->id)->firstOrFail()->forceFill([
            'data_access_expires_at' => now()->subMinute(),
            'user_token_expires_at' => now()->addDays(10),
            'status' => MetaConnectionStatus::Connected,
        ])->save();

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Blocked by data access',
        ])->assertOk();

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('meta');

        $connection = MetaConnection::query()->where('store_id', $store->id)->firstOrFail();
        $this->assertSame(MetaConnectionStatus::NeedsReauth, $connection->status);
        $this->assertSame('data_access_expired', $connection->last_error_code);
        $this->assertStringContainsString('Reconnect', (string) $connection->publicErrorGuidance());
    }

    public function test_connection_resource_exposes_safe_error_guidance(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectStore($admin, $store);

        $connection = MetaConnection::query()->where('store_id', $store->id)->firstOrFail();
        $connection->markNeedsReauth(
            'invalid_token',
            'Meta rejected credentials. access_token=EAAShouldNotLeak&foo=1',
        );

        $payload = $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/meta/connection")
            ->assertOk()
            ->json('data.connection');

        $this->assertSame('needs_reauth', $payload['status']);
        $this->assertSame('invalid_token', $payload['last_error_code']);
        $this->assertIsString($payload['last_error_message']);
        $this->assertStringNotContainsString('EAAShouldNotLeak', $payload['last_error_message']);
        $this->assertArrayNotHasKey('user_access_token', $payload);
        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('fake-meta-user-token', $encoded);
    }

    public function test_structured_logs_include_ids_but_not_credentials(): void
    {
        Log::spy();

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Log me',
        ])->assertOk();

        $response = $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertStatus(202);

        $publicationId = $response->json('data.0.id');

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($publicationId, $store) {
                if ($message !== 'meta.publication.transition') {
                    return false;
                }
                $encoded = json_encode($context);

                return ($context['publication_id'] ?? null) === $publicationId
                    && ($context['store_id'] ?? null) === $store->id
                    && ($context['status'] ?? null) === 'published'
                    && array_key_exists('attempts', $context)
                    && array_key_exists('simulated', $context)
                    && ! str_contains($encoded, 'fake-page-token')
                    && ! str_contains($encoded, 'fake-meta-user-token')
                    && ! str_contains($encoded, 'access_token');
            })
            ->atLeast()
            ->once();
    }

    public function test_mark_published_requires_facebook_verification_ack(): void
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
            'idempotency_key' => 'mark-pub-1',
            'content_hash' => hash('sha256', 'x'),
            'message_snapshot' => 'x',
            'attempts' => 1,
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications/{$publication->id}/resolve", [
                'resolution' => 'mark_published',
                'provider_post_id' => 'manual_post_1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acknowledge_verified_on_facebook');

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications/{$publication->id}/resolve", [
                'resolution' => 'mark_published',
                'acknowledge_verified_on_facebook' => true,
                'provider_post_id' => 'manual_post_1',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.provider_post_id', 'manual_post_1');
    }

    public function test_publish_lease_default_exceeds_job_timeout(): void
    {
        $lease = (int) config('services.meta.publish_lease_seconds', 180);
        $job = new ProcessMetaPublicationJob('x');
        $this->assertGreaterThan($job->timeout, $lease);
    }

    public function test_debug_token_zero_timestamps_are_not_persisted_as_expiry(): void
    {
        config([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => 'app',
            'services.meta.app_secret' => 'secret',
            'services.meta.redirect_uri' => 'https://example.test/api/v1/meta/oauth/callback',
            'services.meta.graph_version' => 'v25.0',
        ]);
        $this->app->forgetInstance(MetaProvider::class);
        $this->app->singleton(MetaProvider::class, fn () => new GraphMetaProvider);

        Http::fake([
            'https://graph.facebook.com/v25.0/debug_token*' => Http::response([
                'data' => [
                    'is_valid' => true,
                    'expires_at' => 0,
                    'data_access_expires_at' => 0,
                    'scopes' => ['pages_show_list'],
                ],
            ], 200),
        ]);

        $result = app(MetaProvider::class)->inspectUserAccessToken('user-token');
        $this->assertTrue($result['is_valid']);
        $this->assertNull($result['expires_at']);
        $this->assertNull($result['data_access_expires_at']);
    }

    /** @return array{0: Campaign, 1: MetaPage} */
    private function readyCampaign($admin, $store): array
    {
        $this->connectAndSelect($admin, $store);
        $page = MetaPage::query()->where('store_id', $store->id)->where('meta_page_id', 'fake-page-100')->firstOrFail();
        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => 'draft',
            'subject' => 'Email',
            'body' => 'Body',
        ]);

        return [$campaign, $page];
    }

    private function connectAndSelect($admin, $store): void
    {
        $this->connectStore($admin, $store);
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/meta/pages/select", [
            'page_ids' => ['fake-page-100'],
            'default_page_id' => 'fake-page-100',
        ])->assertOk();
    }

    private function connectStore($admin, $store): void
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
