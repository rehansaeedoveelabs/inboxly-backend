<?php

namespace Tests\Feature;

use App\Enums\MetaConnectionStatus;
use App\Enums\MetaPublicationStatus;
use App\Jobs\ProcessMetaPublicationJob;
use App\Models\Campaign;
use App\Models\CampaignMetaPublication;
use App\Models\MetaConnection;
use App\Models\MetaPage;
use App\Models\User;
use App\Support\Meta\FakeMetaProvider;
use App\Support\Meta\MetaPublishRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class MetaPublicationReliabilityTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMetaProvider::resetPublishedFeed();
        config(['services.meta.allow_fake_publish' => true]);
    }

    public function test_graph_transport_timeout_is_outcome_unknown_without_auto_retry(): void
    {
        config([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => 'app',
            'services.meta.app_secret' => 'secret',
            'services.meta.redirect_uri' => 'https://example.test/api/v1/meta/oauth/callback',
            'services.meta.graph_version' => 'v25.0',
            'services.meta.allow_fake_publish' => false,
            'services.meta.publish_max_attempts' => 3,
        ]);
        $this->app->forgetInstance(\App\Support\Meta\Contracts\MetaProvider::class);
        $this->app->singleton(
            \App\Support\Meta\Contracts\MetaProvider::class,
            fn () => new \App\Support\Meta\GraphMetaProvider,
        );

        $feedCalls = 0;
        Http::fake(function ($request) use (&$feedCalls) {
            $url = $request->url();
            if (str_contains($url, '/me/accounts')) {
                return Http::response([
                    'data' => [[
                        'id' => 'page-9',
                        'name' => 'Ada Page',
                        'access_token' => 'fresh-page-token',
                        'tasks' => ['CREATE_CONTENT', 'MANAGE'],
                    ]],
                ], 200);
            }
            if (str_contains($url, '/page-9/feed') && $request->method() === 'POST') {
                $feedCalls++;
                throw new ConnectionException('Connection timed out after Meta may have accepted the post.');
            }

            return Http::response(['error' => ['message' => 'unexpected']], 500);
        });

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->seedGraphPage($admin, $store);

        $publication = CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::Queued,
            'simulated' => false,
            'idempotency_key' => 'graph-timeout-1',
            'content_hash' => hash('sha256', 'Graph timeout||'),
            'message_snapshot' => 'Graph timeout',
            'attempts' => 0,
            'queued_at' => now(),
        ]);

        (new ProcessMetaPublicationJob($publication->getKey()))->handle(
            app(\App\Support\Meta\Contracts\MetaProvider::class),
            app(\App\Actions\Meta\RefreshMetaPageToken::class),
        );

        $publication->refresh();
        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->status);
        $this->assertSame('ambiguous_transport', $publication->error_code);
        $this->assertFalse((bool) $publication->error_retryable);
        $this->assertSame(1, $feedCalls);

        // Automatic second attempt must not publish again.
        (new ProcessMetaPublicationJob($publication->getKey()))->handle(
            app(\App\Support\Meta\Contracts\MetaProvider::class),
            app(\App\Actions\Meta\RefreshMetaPageToken::class),
        );
        $this->assertSame(1, $feedCalls);
        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->fresh()->status);
    }

    public function test_stale_worker_cannot_overwrite_outcome_unknown(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $publication = CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::Publishing,
            'simulated' => true,
            'idempotency_key' => 'stale-worker-1',
            'content_hash' => hash('sha256', 'stale'),
            'message_snapshot' => 'stale',
            'attempts' => 1,
            'lease_token' => '01LEASESTALEWORKERTOKEN0001',
            'lease_expires_at' => now()->addMinutes(2),
            'publishing_started_at' => now(),
        ]);

        // Newer state wins.
        $publication->forceFill([
            'status' => MetaPublicationStatus::OutcomeUnknown,
            'lease_token' => null,
            'lease_expires_at' => null,
            'error_code' => 'ambiguous_outcome',
        ])->save();

        $updated = \Illuminate\Support\Facades\DB::table('campaign_meta_publications')
            ->where('id', $publication->getKey())
            ->where('status', MetaPublicationStatus::Publishing->value)
            ->where('lease_token', '01LEASESTALEWORKERTOKEN0001')
            ->update([
                'status' => MetaPublicationStatus::Published->value,
                'provider_post_id' => 'should-not-write',
            ]);

        $this->assertSame(0, $updated);
        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->fresh()->status);
        $this->assertNull($publication->fresh()->provider_post_id);
    }

    public function test_expired_lease_recovery_is_atomic_and_does_not_republish(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $publication = CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::Publishing,
            'simulated' => true,
            'idempotency_key' => 'lease-recover-1',
            'content_hash' => hash('sha256', 'lease'),
            'message_snapshot' => 'lease',
            'attempts' => 1,
            'lease_token' => '01LEASEEXPIREDTOKEN00000001',
            'lease_expires_at' => now()->subMinute(),
            'publishing_started_at' => now()->subMinutes(5),
        ]);

        $before = FakeMetaProvider::publishedFeed('fake-page-100');

        (new ProcessMetaPublicationJob($publication->getKey()))->handle(
            app(\App\Support\Meta\Contracts\MetaProvider::class),
            app(\App\Actions\Meta\RefreshMetaPageToken::class),
        );

        $publication->refresh();
        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->status);
        $this->assertSame($before, FakeMetaProvider::publishedFeed('fake-page-100'));
    }

    public function test_reconcile_rejects_message_only_evidence(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        FakeMetaProvider::resetPublishedFeed();
        $provider = new FakeMetaProvider;
        // Old post outside the attempt window (created_time = now, attempt was yesterday).
        $provider->publishPagePost(new MetaPublishRequest(
            pageId: 'fake-page-100',
            pageAccessToken: 'fake-page-token-100',
            message: 'Same words',
        ));
        // Backdate the feed entry.
        $feed = FakeMetaProvider::publishedFeed('fake-page-100');
        $this->assertNotEmpty($feed);

        $publication = CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::OutcomeUnknown,
            'simulated' => true,
            'idempotency_key' => 'msg-only-1',
            'content_hash' => hash('sha256', 'Same words||'),
            'message_snapshot' => 'Same words',
            'attempts' => 1,
            'publishing_started_at' => now()->subDays(2),
            'queued_at' => now()->subDays(2),
            'error_code' => 'ambiguous_outcome',
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications/{$publication->id}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', 'outcome_unknown')
            ->assertJsonPath('data.error_code', 'not_found_on_page');
    }

    public function test_reconcile_accepts_unique_message_and_time_window(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        FakeMetaProvider::resetPublishedFeed();
        $provider = new FakeMetaProvider;
        $result = $provider->publishPagePost(new MetaPublishRequest(
            pageId: 'fake-page-100',
            pageAccessToken: 'fake-page-token-100',
            message: 'Window match',
            link: 'https://shop.example.com/a',
        ));

        $publication = CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::OutcomeUnknown,
            'simulated' => true,
            'idempotency_key' => 'window-1',
            'content_hash' => hash('sha256', 'Window match|https://shop.example.com/a|'),
            'message_snapshot' => 'Window match',
            'link_snapshot' => 'https://shop.example.com/a',
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

    public function test_resolve_unknown_mark_failed_requires_duplicate_risk_ack(): void
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
            'idempotency_key' => 'resolve-1',
            'content_hash' => hash('sha256', 'resolve'),
            'message_snapshot' => 'resolve',
            'attempts' => 1,
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications/{$publication->id}/resolve", [
                'resolution' => 'mark_failed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acknowledge_duplicate_risk');

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications/{$publication->id}/resolve", [
                'resolution' => 'mark_failed',
                'acknowledge_duplicate_risk' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');
    }

    public function test_intentional_republish_versus_accidental_duplicate(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Repeat me',
        ])->assertOk();

        $first = $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertStatus(202)->json('data.0.id');

        $this->assertSame('published', CampaignMetaPublication::query()->find($first)->status->value);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('confirm_republish');

        $second = $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
            'confirm_republish' => true,
        ])->assertStatus(202)->json('data.0.id');

        $this->assertNotSame($first, $second);
        $secondRow = CampaignMetaPublication::query()->find($second);
        $this->assertNotNull($secondRow->publish_intent);
        $this->assertSame(MetaPublicationStatus::Published, $secondRow->status);
        $this->assertSame(2, CampaignMetaPublication::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_concurrent_first_insert_idempotency_conflict_is_safe(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'Conflict',
        ])->assertOk();

        $campaign->refresh();
        $link = $campaign->meta_link;
        $hash = CampaignMetaPublication::makeContentHash(
            (string) $campaign->meta_message,
            $link,
            $campaign->meta_asset_id,
        );
        $key = CampaignMetaPublication::makeIdempotencyKey((string) $campaign->id, (string) $page->id, $hash);

        // Draft row occupies the unique key without being a reusable failed/cancelled row.
        CampaignMetaPublication::query()->create([
            'store_id' => $store->id,
            'campaign_id' => $campaign->id,
            'meta_page_id' => $page->id,
            'status' => MetaPublicationStatus::Draft,
            'simulated' => true,
            'idempotency_key' => $key,
            'content_hash' => $hash,
            'message_snapshot' => 'Conflict',
            'attempts' => 0,
        ]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('meta');
    }

    public function test_missing_granted_scopes_fail_closed(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->readyCampaign($admin, $store);

        MetaConnection::query()->where('store_id', $store->id)->firstOrFail()
            ->forceFill(['scopes' => []])
            ->save();

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaign->id}/meta/social-content", [
            'message' => 'No scopes',
        ])->assertOk();

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('meta');
    }

    public function test_fake_feed_is_page_isolated(): void
    {
        FakeMetaProvider::resetPublishedFeed();
        $provider = new FakeMetaProvider;
        $provider->publishPagePost(new MetaPublishRequest(
            pageId: 'fake-page-100',
            pageAccessToken: 't',
            message: 'Only A',
        ));
        $provider->publishPagePost(new MetaPublishRequest(
            pageId: 'fake-page-200',
            pageAccessToken: 't',
            message: 'Only B',
        ));

        $pageA = $provider->listRecentPagePosts('fake-page-100', 't');
        $pageB = $provider->listRecentPagePosts('fake-page-200', 't');

        $this->assertCount(1, $pageA);
        $this->assertSame('Only A', $pageA[0]['message']);
        $this->assertCount(1, $pageB);
        $this->assertSame('Only B', $pageB[0]['message']);
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

    /** @return array{0: Campaign, 1: MetaPage} */
    private function seedGraphPage(User $admin, $store): array
    {
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
            'meta_message' => 'Graph timeout',
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
