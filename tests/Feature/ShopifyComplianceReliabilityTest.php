<?php

namespace Tests\Feature;

use App\Actions\Shopify\AcceptShopifyComplianceWebhook;
use App\Actions\Shopify\ClaimShopifyComplianceRequest;
use App\Actions\Shopify\Compliance\ProcessCustomersDataRequest;
use App\Actions\Shopify\Compliance\ProcessShopRedact;
use App\Actions\Shopify\RecoverStrandedShopifyComplianceRequests;
use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Jobs\ProcessShopifyComplianceRequestJob;
use App\Models\Contact;
use App\Models\ContactExport;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use App\Support\Shopify\ShopifyComplianceCorrelation;
use App\Support\Shopify\ShopifyComplianceRuntimeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyComplianceReliabilityTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    private function store(string $domain = 'reliability-shop.myshopify.com'): Store
    {
        [, $workspace] = $this->adminWithWorkspace();

        return Store::factory()->withShopifyCredentials($domain)->create([
            'workspace_id' => $workspace->id,
        ])->fresh();
    }

    private function withCorrelation(ShopifyComplianceRequest $request, string $email): ShopifyComplianceRequest
    {
        Storage::fake('local');
        $path = 'compliance/'.$request->getKey().'/correlation.enc';
        Storage::disk('local')->put($path, Crypt::encryptString(json_encode([
            'shopify_customer_id' => $request->shopify_customer_id,
            'email' => $email,
            'phone' => null,
        ], JSON_THROW_ON_ERROR)));

        $request->forceFill([
            'correlation_disk' => 'local',
            'correlation_path' => $path,
        ])->save();

        return $request->fresh();
    }

    public function test_correlation_survives_processor_crash_before_finalize_and_replay_completes(): void
    {
        $store = $this->store();
        Contact::factory()->create([
            'store_id' => $store->id,
            'email' => 'survive@example.com',
        ]);

        $request = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'shop_domain' => $store->shopify_shop_domain,
            'topic' => ShopifyComplianceTopic::CustomersDataRequest,
            'status' => ShopifyComplianceStatus::Received,
            'webhook_id' => 'corr-survive-1',
            'shopify_data_request_id' => '1001',
            'processing_checkpoint' => [],
        ]);
        $request = $this->withCorrelation($request, 'survive@example.com');

        $claim = app(ClaimShopifyComplianceRequest::class)->handle($request->id, 120);
        $this->assertNotNull($claim);
        $owned = $claim['request'];
        $token = $claim['lease_token'];

        // Simulate crash after processor side effects but before owned finalize.
        $result = app(ProcessCustomersDataRequest::class)->handle($owned);
        $this->assertTrue($result['clear_correlation']);
        $mid = $owned->fresh();
        $this->assertNotNull($mid->correlation_path);
        $this->assertNotNull(ShopifyComplianceCorrelation::read($mid));
        $this->assertSame(ShopifyComplianceStatus::Processing, $mid->status);
        // Checkpoint not yet durable for data_request until finalize.
        $this->assertTrue($mid->hasInventoryArtifact());

        // Replay with correlation still present must rebuild a complete inventory.
        $replay = app(ProcessCustomersDataRequest::class)->handle($mid->fresh());
        $this->assertSame(1, $replay['inventory_summary']['matched_contact_count'] ?? null);

        $job = new ProcessShopifyComplianceRequestJob($mid->id);
        $finalize = new ReflectionMethod($job, 'finalize');
        $finalize->setAccessible(true);
        $finalize->invoke(
            $job,
            $mid->fresh(),
            $token,
            $replay,
            app(\App\Actions\Shopify\RecordShopifyComplianceEvent::class),
        );

        $done = $mid->fresh();
        $this->assertSame(ShopifyComplianceStatus::Completed, $done->status);
        $this->assertNull($done->correlation_path);
        $this->assertNull($done->correlation_disk);
        $this->assertSame(1, $done->inventory_summary['matched_contact_count'] ?? null);
        $this->assertFalse(Storage::disk('local')->exists('compliance/'.$done->id.'/correlation.enc'));
    }

    public function test_stale_lease_owner_cannot_finalize_or_clear_correlation_after_reclaim(): void
    {
        Storage::fake('local');
        $store = $this->store('stale-lease.myshopify.com');
        $request = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Received,
            'webhook_id' => 'stale-finalize-1',
            'shopify_data_request_id' => null,
            'topic' => ShopifyComplianceTopic::CustomersRedact,
            'processing_checkpoint' => [],
        ]);
        $request = $this->withCorrelation($request, 'stale@example.com');

        $claim = app(ClaimShopifyComplianceRequest::class);
        $first = $claim->handle($request->id, 120);
        $this->assertNotNull($first);
        $staleToken = $first['lease_token'];

        $first['request']->forceFill([
            'processing_lease_expires_at' => now()->subMinute(),
        ])->save();

        $second = $claim->handle($request->id, 120);
        $this->assertNotNull($second);
        $this->assertNotSame($staleToken, $second['lease_token']);
        $this->assertNotNull($second['request']->correlation_path);

        $job = new ProcessShopifyComplianceRequestJob($request->id);
        $transition = new ReflectionMethod($job, 'transitionOwned');
        $transition->setAccessible(true);

        $staleFinalize = $transition->invoke(
            $job,
            $second['request']->fresh(),
            $staleToken,
            [
                'status' => ShopifyComplianceStatus::Completed,
                'completed_at' => now(),
                'processing_checkpoint' => ['bogus' => true],
            ],
            true,
        );
        $this->assertFalse($staleFinalize);

        $fresh = $request->fresh();
        $this->assertSame(ShopifyComplianceStatus::Processing, $fresh->status);
        $this->assertSame($second['lease_token'], $fresh->processing_lease_token);
        $this->assertNotNull($fresh->correlation_path);
        $this->assertNotNull(ShopifyComplianceCorrelation::read($fresh));
    }

    public function test_dispatch_failure_leaves_request_eligible_for_recovery(): void
    {
        $store = $this->store('dispatch-fail.myshopify.com');
        $request = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Received,
            'webhook_id' => 'dispatch-fail-1',
            'shopify_data_request_id' => null,
            'received_at' => now()->subMinutes(10),
        ]);

        $this->mock(AcceptShopifyComplianceWebhook::class, function ($mock) {
            $mock->shouldReceive('dispatchProcessing')->once()->andReturn(false);
        });

        $result = app(RecoverStrandedShopifyComplianceRequests::class)->handle(
            olderThanSeconds: 60,
            limit: 10,
        );

        $this->assertSame(0, $result['received']);
        $this->assertSame(1, $result['dispatch_failures']);
        $this->assertSame(ShopifyComplianceStatus::Received, $request->fresh()->status);
        // Remains eligible for a later recovery pass (status/age unchanged).
        $this->assertSame(
            1,
            ShopifyComplianceRequest::query()
                ->where('status', ShopifyComplianceStatus::Received)
                ->where('received_at', '<=', now()->subSeconds(60))
                ->whereKey($request->id)
                ->count(),
        );
    }

    public function test_shop_redact_replay_skips_completed_checkpoint_steps(): void
    {
        Storage::fake('local');
        $store = $this->store('shop-redact-replay.myshopify.com');
        $workspace = $store->workspace()->first();
        $this->assertNotNull($workspace);
        ShopifyOauthState::query()->create([
            'state' => 'oauth-replay-state-1',
            'user_id' => $workspace->owner_id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_shop_domain,
            'expires_at' => now()->addHour(),
        ]);
        ContactExport::factory()->create([
            'store_id' => $store->id,
            'user_id' => $workspace->owner_id,
            'disk' => 'local',
            'path' => 'exports/replay.csv',
            'status' => \App\Enums\ContactExportStatus::Completed,
        ]);
        Storage::disk('local')->put('exports/replay.csv', 'a,b');

        $request = ShopifyComplianceRequest::factory()->shopRedact()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'shop_domain' => $store->shopify_shop_domain,
            'status' => ShopifyComplianceStatus::Received,
            'webhook_id' => 'shop-redact-replay-1',
            // Simulate crash after credentials cleared but before oauth/export purge.
            'processing_checkpoint' => [
                'resolve_store' => ['done' => true, 'store_found' => true, 'store_trashed' => false],
                'clear_credentials' => ['done' => true, 'cleared' => true],
            ],
        ]);

        $store->disconnectShopify();
        $this->assertNull($store->fresh()->shopify_access_token);

        $claim = app(ClaimShopifyComplianceRequest::class)->handle($request->id, 120);
        $this->assertNotNull($claim);

        $result = app(ProcessShopRedact::class)->handle($claim['request'], $claim['lease_token']);
        $this->assertSame(ShopifyComplianceStatus::Completed, $result['status']);
        $this->assertTrue($result['checkpoint']['clear_credentials']['done'] ?? false);
        $this->assertTrue($result['checkpoint']['purge_oauth_states']['done'] ?? false);
        $this->assertTrue($result['checkpoint']['purge_contact_exports']['done'] ?? false);
        $this->assertSame(0, ShopifyOauthState::query()->where('store_id', $store->id)->count());
        $this->assertSame(0, ContactExport::query()->where('store_id', $store->id)->count());
        $this->assertFalse($result['inventory_summary']['external_backups_completed']);
        $this->assertSame('not_claimed', $result['legal_checklist']['organization_wide_erasure']);

        // Second replay with all steps done must not re-delete; summary keeps durable checkpoint counts.
        $request->forceFill([
            'status' => ShopifyComplianceStatus::Processing,
            'processing_lease_token' => $claim['lease_token'],
            'processing_lease_expires_at' => now()->addMinutes(5),
            'processing_checkpoint' => $result['checkpoint'],
        ])->save();

        $replay = app(ProcessShopRedact::class)->handle($request->fresh(), $claim['lease_token']);
        $this->assertSame(ShopifyComplianceStatus::Completed, $replay['status']);
        $this->assertSame(
            $result['inventory_summary']['oauth_states_deleted'],
            $replay['inventory_summary']['oauth_states_deleted'],
        );
        $this->assertSame(
            $result['inventory_summary']['exports_deleted'],
            $replay['inventory_summary']['exports_deleted'],
        );
        $this->assertSame(0, ShopifyOauthState::query()->where('store_id', $store->id)->count());
        $this->assertSame(0, ContactExport::query()->where('store_id', $store->id)->count());
    }

    public function test_runtime_guard_flags_unsafe_retry_after_and_documents_lease_basis(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 30,
            'services.shopify.compliance_processing_lease_seconds' => 300,
        ]);

        $result = ShopifyComplianceRuntimeGuard::evaluate(log: false);
        $this->assertNotEmpty($result['notes']);
        $this->assertTrue(
            collect($result['notes'])->contains(fn (string $n) => str_contains($n, 'job timeout')),
        );
        $this->assertTrue(
            collect($result['warnings'])->contains(fn (string $w) => str_contains($w, 'retry_after=30')),
        );
    }

    public function test_true_multiprocess_claim_concurrency_is_not_proven_here(): void
    {
        $this->markTestSkipped(
            'True multi-process / multi-connection claim races are not practical in this PHPUnit environment without a dedicated harness. Serial ownership is covered elsewhere; do not treat sequential double-claim as a concurrency proof.'
        );
    }
}
