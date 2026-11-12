<?php

namespace Tests\Feature;

use App\Actions\Shopify\ClaimShopifyComplianceRequest;
use App\Actions\Shopify\RecoverStrandedShopifyComplianceRequests;
use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Jobs\ProcessShopifyComplianceRequestJob;
use App\Models\ShopifyComplianceRequest;
use App\Models\Store;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyComplianceRecoveryTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    private function store(): Store
    {
        [, $workspace] = $this->adminWithWorkspace();

        return Store::factory()->withShopifyCredentials('recover-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
        ]);
    }

    public function test_processing_lease_default_exceeds_job_timeout(): void
    {
        $lease = (int) config('services.shopify.compliance_processing_lease_seconds', 300);
        $job = new ProcessShopifyComplianceRequestJob('x');

        $this->assertGreaterThan($job->timeout, $lease);
    }

    public function test_claim_rejects_live_lease_and_allows_expired_lease(): void
    {
        $store = $this->store();
        $live = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Processing,
            'webhook_id' => 'lease-live-1',
            'shopify_data_request_id' => null,
            'processing_started_at' => now()->subMinutes(1),
            'processing_lease_expires_at' => now()->addMinutes(5),
            'processing_lease_token' => '01LIVELEASETOKENXXXXXXX',
            'processing_checkpoint' => ['resolve_store' => ['done' => true]],
            'attempt_count' => 1,
        ]);
        $expired = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Processing,
            'webhook_id' => 'lease-expired-1',
            'shopify_data_request_id' => null,
            'processing_started_at' => now()->subHours(1),
            'processing_lease_expires_at' => now()->subMinutes(5),
            'processing_lease_token' => '01EXPIREDLEASETOKENXXXX',
            'processing_checkpoint' => ['resolve_store' => ['done' => true], 'build_inventory' => ['done' => true]],
            'attempt_count' => 2,
        ]);

        $claim = app(ClaimShopifyComplianceRequest::class);

        $this->assertNull($claim->handle($live->id, 120));
        $this->assertSame(1, $live->fresh()->attempt_count);
        $this->assertSame(
            ['resolve_store' => ['done' => true]],
            $live->fresh()->processing_checkpoint,
        );

        $reclaimed = $claim->handle($expired->id, 120);
        $this->assertNotNull($reclaimed);
        $this->assertSame(ShopifyComplianceStatus::Processing, $reclaimed['request']->status);
        $this->assertSame(3, $reclaimed['request']->attempt_count);
        $this->assertNotSame('01EXPIREDLEASETOKENXXXX', $reclaimed['lease_token']);
        $this->assertSame(
            ['resolve_store' => ['done' => true], 'build_inventory' => ['done' => true]],
            $reclaimed['request']->processing_checkpoint,
        );
        $this->assertTrue($reclaimed['request']->processing_lease_expires_at->isFuture());
    }

    public function test_recovery_redispatches_stranded_received_and_expired_processing_only(): void
    {
        Queue::fake();
        $store = $this->store();

        $stranded = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Received,
            'webhook_id' => 'recv-stranded-1',
            'shopify_data_request_id' => null,
            'received_at' => now()->subMinutes(10),
        ]);
        $freshReceived = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Received,
            'webhook_id' => 'recv-fresh-1',
            'shopify_data_request_id' => null,
            'received_at' => now(),
        ]);
        $expiredProcessing = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Processing,
            'webhook_id' => 'proc-expired-1',
            'shopify_data_request_id' => null,
            'processing_started_at' => now()->subHours(2),
            'processing_lease_expires_at' => now()->subMinutes(10),
            'processing_lease_token' => '01STUCKTOKENXXXXXXXXXX',
            'processing_checkpoint' => ['resolve_store' => ['done' => true]],
        ]);
        $liveProcessing = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Processing,
            'webhook_id' => 'proc-live-1',
            'shopify_data_request_id' => null,
            'processing_started_at' => now(),
            'processing_lease_expires_at' => now()->addMinutes(10),
            'processing_lease_token' => '01LIVETOKENXXXXXXXXXXX',
        ]);
        $awaiting = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::AwaitingPolicy,
            'webhook_id' => 'await-1',
            'shopify_data_request_id' => null,
            'topic' => ShopifyComplianceTopic::CustomersRedact,
        ]);
        $completed = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Completed,
            'webhook_id' => 'done-1',
            'shopify_data_request_id' => null,
        ]);

        $result = app(RecoverStrandedShopifyComplianceRequests::class)->handle(
            olderThanSeconds: 60,
            limit: 50,
        );

        $this->assertSame(1, $result['received']);
        $this->assertSame(1, $result['expired_processing']);
        $this->assertSame(0, $result['dispatch_failures']);

        Queue::assertPushed(ProcessShopifyComplianceRequestJob::class, 2);
        Queue::assertPushed(
            ProcessShopifyComplianceRequestJob::class,
            fn (ProcessShopifyComplianceRequestJob $job) => $job->complianceRequestId === $stranded->id,
        );
        Queue::assertPushed(
            ProcessShopifyComplianceRequestJob::class,
            fn (ProcessShopifyComplianceRequestJob $job) => $job->complianceRequestId === $expiredProcessing->id,
        );

        $this->assertDatabaseHas('shopify_compliance_request_events', [
            'compliance_request_id' => $stranded->id,
            'event_type' => ShopifyComplianceRequestEventType::RecoveryDispatched->value,
        ]);
        $this->assertDatabaseHas('shopify_compliance_request_events', [
            'compliance_request_id' => $expiredProcessing->id,
            'event_type' => ShopifyComplianceRequestEventType::RecoveryDispatched->value,
        ]);

        $this->assertSame(ShopifyComplianceStatus::Received, $freshReceived->fresh()->status);
        $this->assertSame(ShopifyComplianceStatus::Processing, $liveProcessing->fresh()->status);
        $this->assertSame(ShopifyComplianceStatus::AwaitingPolicy, $awaiting->fresh()->status);
        $this->assertSame(ShopifyComplianceStatus::Completed, $completed->fresh()->status);
        $this->assertSame(
            ['resolve_store' => ['done' => true]],
            $expiredProcessing->fresh()->processing_checkpoint,
        );
    }

    public function test_serial_second_claim_loses_while_lease_is_live(): void
    {
        // Serial ownership check only — not a multi-process concurrency proof.
        $store = $this->store();
        $request = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Received,
            'webhook_id' => 'concurrent-1',
            'shopify_data_request_id' => null,
            'received_at' => now()->subMinute(),
        ]);

        $claim = app(ClaimShopifyComplianceRequest::class);
        $first = $claim->handle($request->id, 120);
        $second = $claim->handle($request->id, 120);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $request->fresh()->attempt_count);
        $this->assertSame($first['lease_token'], $request->fresh()->processing_lease_token);
    }

    public function test_recover_command_is_scheduled_with_overlap_guard(): void
    {
        // withSchedule callbacks register on Artisan::starting — call schedule:list first.
        Artisan::call('schedule:list');
        $this->assertStringContainsString('shopify:recover-compliance-requests', Artisan::output());

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $matched = collect($schedule->events())->first(function ($e) {
            return str_contains((string) ($e->command ?? ''), 'shopify:recover-compliance-requests');
        });

        $this->assertNotNull($matched, 'Expected shopify:recover-compliance-requests on the schedule.');
        $this->assertSame('*/5 * * * *', $matched->expression);
        $this->assertTrue($matched->withoutOverlapping);
    }

    public function test_ops_report_lists_null_workspace_without_pii(): void
    {
        ShopifyComplianceRequest::factory()->withoutStore()->create([
            'workspace_id' => null,
            'shop_domain' => 'orphan-ops.myshopify.com',
            'webhook_id' => 'ops-orphan-1',
            'shopify_data_request_id' => null,
            'topic' => ShopifyComplianceTopic::ShopRedact,
            'status' => ShopifyComplianceStatus::Received,
            'received_at' => now()->subHours(2),
            'correlation_path' => 'compliance/secret/correlation.enc',
            'inventory_path' => 'compliance/secret/inventory.json',
            'error_message' => 'should-not-print-if-present',
        ]);

        $exit = Artisan::call('shopify:compliance-ops-report', [
            '--json' => true,
            '--received-older-than' => 60,
        ]);
        $this->assertSame(0, $exit);
        $output = Artisan::output();

        $this->assertStringContainsString('orphan-ops.myshopify.com', $output);
        $this->assertStringContainsString('null_store_workspace', $output);
        $this->assertStringContainsString('not an alerting system', strtolower($output));
        $this->assertStringNotContainsString('correlation.enc', $output);
        $this->assertStringNotContainsString('inventory.json', $output);
        $this->assertStringNotContainsString('should-not-print-if-present', $output);
        $this->assertStringNotContainsString('@', $output);
    }
}
