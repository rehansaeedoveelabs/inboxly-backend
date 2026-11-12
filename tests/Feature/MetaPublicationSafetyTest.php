<?php

namespace Tests\Feature;

use App\Actions\Meta\RefreshMetaPageToken;
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
use App\Support\Meta\MetaDriverGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class MetaPublicationSafetyTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMetaProvider::resetPublishedFeed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainHttpStatusProvider')]
    public function test_uncertain_http_status_becomes_outcome_unknown_without_second_post(int $status): void
    {
        [$publication, $feedCalls] = $this->runGraphPublishJobReturningFeedCounter(
            Http::response(['error' => ['message' => 'gateway']], $status),
        );

        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->status);
        $this->assertSame('ambiguous_http_'.$status, $publication->error_code);
        $this->assertFalse((bool) $publication->error_retryable);
        $this->assertSame(1, $feedCalls());

        (new ProcessMetaPublicationJob($publication->getKey()))->handle(
            app(MetaProvider::class),
            app(RefreshMetaPageToken::class),
        );
        $this->assertSame(1, $feedCalls());
        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->fresh()->status);
    }

    public static function uncertainHttpStatusProvider(): array
    {
        return [
            '500' => [500],
            '502' => [502],
            '503' => [503],
            '504' => [504],
        ];
    }

    public function test_success_without_post_id_is_outcome_unknown(): void
    {
        [$publication, $feedCalls] = $this->runGraphPublishJobReturningFeedCounter(
            Http::response(['success' => true], 200),
        );

        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->status);
        $this->assertSame('missing_provider_post_id', $publication->error_code);
        $this->assertSame(1, $feedCalls());
    }

    public function test_http_429_retains_bounded_retry(): void
    {
        $this->bindGraphProvider([
            'services.meta.publish_max_attempts' => 3,
        ]);

        // Prevent sync-queue from immediately draining delayed re-dispatches.
        \Illuminate\Support\Facades\Queue::fake();

        $feedCalls = 0;
        Http::fake(function ($request) use (&$feedCalls) {
            if (str_contains($request->url(), '/me/accounts')) {
                return $this->accountsResponse();
            }
            if (str_contains($request->url(), '/page-9/feed') && $request->method() === 'POST') {
                $feedCalls++;

                return Http::response(['error' => ['message' => 'rate limit', 'code' => 4]], 429);
            }

            return Http::response(['error' => ['message' => 'unexpected']], 500);
        });

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->seedGraphPage($admin, $store);

        $publication = $this->queuedPublication($store->id, $campaign->id, $page->id, 'rate-limit-1', 'Rate limit');

        (new ProcessMetaPublicationJob($publication->getKey()))->handle(
            app(MetaProvider::class),
            app(RefreshMetaPageToken::class),
        );

        $publication->refresh();
        $this->assertSame(MetaPublicationStatus::Queued, $publication->status);
        $this->assertTrue((bool) $publication->error_retryable);
        $this->assertSame('rate_limited', $publication->error_code);
        $this->assertSame(1, $feedCalls);
        \Illuminate\Support\Facades\Queue::assertPushed(ProcessMetaPublicationJob::class);

        // Drain remaining attempts manually (ignore delay).
        for ($i = 0; $i < 5; $i++) {
            if ($publication->fresh()->status !== MetaPublicationStatus::Queued) {
                break;
            }
            (new ProcessMetaPublicationJob($publication->getKey()))->handle(
                app(MetaProvider::class),
                app(RefreshMetaPageToken::class),
            );
        }

        $publication->refresh();
        $this->assertSame(MetaPublicationStatus::Failed, $publication->status);
        $this->assertSame(3, $feedCalls);
        $this->assertSame(3, $publication->attempts);
    }

    public function test_transport_timeout_remains_ambiguous(): void
    {
        [$publication, $feedCalls] = $this->runGraphPublishJobReturningFeedCounter(
            function () {
                throw new ConnectionException('timed out');
            },
        );

        $this->assertSame(MetaPublicationStatus::OutcomeUnknown, $publication->status);
        $this->assertSame('ambiguous_transport', $publication->error_code);
        $this->assertSame(1, $feedCalls());
    }

    public function test_fake_publish_denied_outside_local_testing_even_with_allow_flag(): void
    {
        $previous = $this->app['env'];
        $this->app['env'] = 'staging';

        try {
            config([
                'services.meta.driver' => 'fake',
                'services.meta.allow_fake_publish' => true,
            ]);

            $this->expectException(ValidationException::class);
            MetaDriverGuard::assertPublishAllowed();
        } finally {
            $this->app['env'] = $previous;
        }
    }

    public function test_local_testing_fake_publishing_still_works(): void
    {
        $this->assertSame('testing', $this->app->environment());
        config([
            'services.meta.driver' => 'fake',
            'services.meta.allow_fake_publish' => false,
        ]);

        MetaDriverGuard::assertPublishAllowed();
        $this->assertTrue(MetaDriverGuard::isFake());

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->connectAndSelect($admin, $store);
        $page = MetaPage::query()->where('store_id', $store->id)->where('meta_page_id', 'fake-page-100')->firstOrFail();
        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => 'draft',
            'meta_message' => 'Local fake ok',
        ]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaign->id}/meta/publications", [
            'meta_page_ids' => [$page->id],
        ])->assertStatus(202)
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.simulated', true);
    }

    public function test_real_driver_missing_configuration_denied_before_graph(): void
    {
        config([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => '',
            'services.meta.app_secret' => '',
            'services.meta.redirect_uri' => '',
            'services.meta.allow_fake_publish' => false,
        ]);
        $this->app->forgetInstance(MetaProvider::class);
        $this->app->singleton(MetaProvider::class, fn () => new GraphMetaProvider);

        $graphTouched = false;
        Http::fake(function () use (&$graphTouched) {
            $graphTouched = true;

            return Http::response(['id' => 'should-not-happen'], 200);
        });

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->seedGraphPage($admin, $store);

        $publication = $this->queuedPublication($store->id, $campaign->id, $page->id, 'cfg-missing-1', 'Missing cfg');

        (new ProcessMetaPublicationJob($publication->getKey()))->handle(
            app(MetaProvider::class),
            app(RefreshMetaPageToken::class),
        );

        $publication->refresh();
        $this->assertSame(MetaPublicationStatus::Failed, $publication->status);
        $this->assertSame('meta_not_configured', $publication->error_code);
        $this->assertFalse($graphTouched);
        $lower = mb_strtolower((string) $publication->error_message);
        $this->assertStringNotContainsString('app_secret', $lower);
        $this->assertStringNotContainsString('long-token', $lower);
        $this->assertStringNotContainsString('stale-page-token', $lower);
    }

    /**
     * @param  \Illuminate\Http\Client\Response|callable  $feedResponse
     * @return array{0: CampaignMetaPublication, 1: callable(): int}
     */
    private function runGraphPublishJobReturningFeedCounter(mixed $feedResponse): array
    {
        $this->bindGraphProvider();

        $feedCalls = 0;
        Http::fake(function ($request) use (&$feedCalls, $feedResponse) {
            if (str_contains($request->url(), '/me/accounts')) {
                return $this->accountsResponse();
            }
            if (str_contains($request->url(), '/page-9/feed') && $request->method() === 'POST') {
                $feedCalls++;
                if (is_callable($feedResponse)) {
                    return $feedResponse();
                }

                return $feedResponse;
            }

            return Http::response(['error' => ['message' => 'unexpected']], 418);
        });

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$campaign, $page] = $this->seedGraphPage($admin, $store);

        $publication = $this->queuedPublication(
            $store->id,
            $campaign->id,
            $page->id,
            'safety-'.uniqid('', true),
            'Safety case',
        );

        (new ProcessMetaPublicationJob($publication->getKey()))->handle(
            app(MetaProvider::class),
            app(RefreshMetaPageToken::class),
        );

        return [$publication->fresh(), static fn () => $feedCalls];
    }

    private function bindGraphProvider(array $extra = []): void
    {
        config(array_merge([
            'services.meta.driver' => 'meta',
            'services.meta.app_id' => 'app',
            'services.meta.app_secret' => 'secret',
            'services.meta.redirect_uri' => 'https://example.test/api/v1/meta/oauth/callback',
            'services.meta.graph_version' => 'v25.0',
            'services.meta.allow_fake_publish' => false,
        ], $extra));

        $this->app->forgetInstance(MetaProvider::class);
        $this->app->singleton(MetaProvider::class, fn () => new GraphMetaProvider);
    }

    private function accountsResponse()
    {
        return Http::response([
            'data' => [[
                'id' => 'page-9',
                'name' => 'Ada Page',
                'access_token' => 'fresh-page-token',
                'tasks' => ['CREATE_CONTENT', 'MANAGE'],
            ]],
        ], 200);
    }

    private function queuedPublication(string $storeId, string $campaignId, string $pageId, string $key, string $message): CampaignMetaPublication
    {
        return CampaignMetaPublication::query()->create([
            'store_id' => $storeId,
            'campaign_id' => $campaignId,
            'meta_page_id' => $pageId,
            'status' => MetaPublicationStatus::Queued,
            'simulated' => false,
            'idempotency_key' => $key,
            'content_hash' => hash('sha256', $message.'||'),
            'message_snapshot' => $message,
            'attempts' => 0,
            'queued_at' => now(),
        ]);
    }

    /** @return array{0: Campaign, 1: MetaPage} */
    private function seedGraphPage($admin, $store): array
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
            'meta_message' => 'Graph safety',
        ]);

        return [$campaign, $page];
    }

    private function connectAndSelect($admin, $store): void
    {
        $start = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/meta/oauth/start");
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        $this->get('/api/v1/meta/oauth/callback?'.http_build_query([
            'code' => FakeMetaProvider::FAKE_AUTH_CODE,
            'state' => $query['state'],
        ]))->assertRedirect();

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/meta/pages/select", [
            'page_ids' => ['fake-page-100'],
            'default_page_id' => 'fake-page-100',
        ])->assertOk();
    }
}
