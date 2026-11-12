<?php

namespace App\Console\Commands;

use App\Enums\ShopifyComplianceStatus;
use App\Models\ShopifyComplianceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Operator inspection helper for M1 compliance queues.
 *
 * This is NOT an alerting system. Pair with external monitoring if production SLAs require it.
 * Never prints webhook bodies, emails, phones, tokens, correlation contents, or artifact paths.
 */
class ReportShopifyComplianceRequestsCommand extends Command
{
    protected $signature = 'shopify:compliance-ops-report
                            {--received-older-than= : Seconds for "old received" bucket (default: config)}
                            {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Report Shopify compliance request ops buckets (safe ids only). Not an alerting system.';

    public function handle(): int
    {
        $receivedOlderThan = max(1, (int) ($this->option('received-older-than') ?: config(
            'services.shopify.compliance_ops_received_older_than_seconds',
            3600,
        )));
        $leaseSeconds = max(1, (int) config('services.shopify.compliance_processing_lease_seconds', 300));
        $receivedCutoff = now()->subSeconds($receivedOlderThan);
        $legacyCutoff = now()->subSeconds($leaseSeconds);

        $oldReceived = ShopifyComplianceRequest::query()
            ->where('status', ShopifyComplianceStatus::Received)
            ->where('received_at', '<=', $receivedCutoff)
            ->orderBy('received_at')
            ->limit(100)
            ->get();

        $expiredProcessing = ShopifyComplianceRequest::query()
            ->where('status', ShopifyComplianceStatus::Processing)
            ->where(function ($query) use ($legacyCutoff) {
                $query->where(function ($q) {
                    $q->whereNotNull('processing_lease_expires_at')
                        ->where('processing_lease_expires_at', '<=', now());
                })->orWhere(function ($q) use ($legacyCutoff) {
                    $q->whereNull('processing_lease_expires_at')
                        ->whereNotNull('processing_started_at')
                        ->where('processing_started_at', '<=', $legacyCutoff);
                });
            })
            ->orderBy('processing_started_at')
            ->limit(100)
            ->get();

        $failed = ShopifyComplianceRequest::query()
            ->where('status', ShopifyComplianceStatus::Failed)
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get();

        $awaitingPolicy = ShopifyComplianceRequest::query()
            ->where('status', ShopifyComplianceStatus::AwaitingPolicy)
            ->orderByDesc('awaiting_policy_at')
            ->limit(100)
            ->get();

        $nullWorkspace = ShopifyComplianceRequest::query()
            ->whereNull('store_id')
            ->whereNull('workspace_id')
            ->orderByDesc('received_at')
            ->limit(100)
            ->get();

        $counts = [
            'old_received' => ShopifyComplianceRequest::query()
                ->where('status', ShopifyComplianceStatus::Received)
                ->where('received_at', '<=', $receivedCutoff)
                ->count(),
            'expired_processing' => ShopifyComplianceRequest::query()
                ->where('status', ShopifyComplianceStatus::Processing)
                ->where(function ($query) use ($legacyCutoff) {
                    $query->where(function ($q) {
                        $q->whereNotNull('processing_lease_expires_at')
                            ->where('processing_lease_expires_at', '<=', now());
                    })->orWhere(function ($q) use ($legacyCutoff) {
                        $q->whereNull('processing_lease_expires_at')
                            ->whereNotNull('processing_started_at')
                            ->where('processing_started_at', '<=', $legacyCutoff);
                    });
                })
                ->count(),
            'failed' => ShopifyComplianceRequest::query()
                ->where('status', ShopifyComplianceStatus::Failed)
                ->count(),
            'awaiting_policy' => ShopifyComplianceRequest::query()
                ->where('status', ShopifyComplianceStatus::AwaitingPolicy)
                ->count(),
            'null_store_workspace' => ShopifyComplianceRequest::query()
                ->whereNull('store_id')
                ->whereNull('workspace_id')
                ->count(),
        ];

        $payload = [
            'note' => 'Operator inspection only — not an alerting system. See docs/M1-COMPLIANCE-OPERATIONS.md.',
            'thresholds' => [
                'received_older_than_seconds' => $receivedOlderThan,
                'processing_lease_seconds' => $leaseSeconds,
            ],
            'counts' => $counts,
            'samples' => [
                'old_received' => $this->safeRows($oldReceived),
                'expired_processing' => $this->safeRows($expiredProcessing),
                'failed' => $this->safeRows($failed),
                'awaiting_policy' => $this->safeRows($awaitingPolicy),
                'null_store_workspace' => $this->safeRows($nullWorkspace),
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->warn('Operator inspection only — not monitoring/alerting. Escalate via your ops runbook.');
        $this->table(['bucket', 'count'], collect($counts)->map(fn ($c, $k) => [$k, $c])->values()->all());

        foreach ($payload['samples'] as $bucket => $rows) {
            $this->newLine();
            $this->info($bucket.' (sample ≤100)');
            if ($rows === []) {
                $this->line('  (none)');

                continue;
            }
            $this->table(
                ['id', 'shop_domain', 'topic', 'status', 'store_id', 'workspace_id', 'received_at', 'lease_expires_at', 'error_code'],
                collect($rows)->map(fn (array $r) => [
                    $r['id'],
                    $r['shop_domain'],
                    $r['topic'],
                    $r['status'],
                    $r['store_id'] ?? 'null',
                    $r['workspace_id'] ?? 'null',
                    $r['received_at'] ?? '',
                    $r['processing_lease_expires_at'] ?? '',
                    $r['error_code'] ?? '',
                ])->all(),
            );
        }

        $this->newLine();
        $this->comment('NULL-store/workspace rows are invisible to workspace Admin APIs. Escalate manually by id + shop_domain; do not invent ownership.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, ShopifyComplianceRequest>  $requests
     * @return list<array<string, mixed>>
     */
    private function safeRows(Collection $requests): array
    {
        return $requests->map(static function (ShopifyComplianceRequest $r) {
            return [
                'id' => $r->getKey(),
                'shop_domain' => $r->shop_domain,
                'topic' => $r->topic->value,
                'status' => $r->status->value,
                'store_id' => $r->store_id,
                'workspace_id' => $r->workspace_id,
                'received_at' => optional($r->received_at)?->toIso8601String(),
                'processing_started_at' => optional($r->processing_started_at)?->toIso8601String(),
                'processing_lease_expires_at' => optional($r->processing_lease_expires_at)?->toIso8601String(),
                'error_code' => $r->error_code,
            ];
        })->all();
    }
}
