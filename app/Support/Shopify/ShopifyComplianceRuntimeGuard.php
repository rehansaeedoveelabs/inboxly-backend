<?php

namespace App\Support\Shopify;

use App\Jobs\ProcessShopifyComplianceRequestJob;
use Illuminate\Support\Facades\Log;

/**
 * Emits operational warnings for unsafe compliance queue/lease configuration.
 *
 * Cannot inspect every deployment's `queue:work --timeout` CLI flag. Lease duration is derived
 * from the job's declared `$timeout`, not from an arbitrary worker process timeout.
 */
final class ShopifyComplianceRuntimeGuard
{
    /**
     * @return array{warnings: list<string>, notes: list<string>}
     */
    public static function evaluate(bool $log = true): array
    {
        $warnings = [];
        $notes = [];
        $job = new ProcessShopifyComplianceRequestJob('runtime-guard');
        $jobTimeout = max(1, (int) $job->timeout);
        $lease = max(1, (int) config('services.shopify.compliance_processing_lease_seconds', 300));
        $effectiveLeaseFloor = $jobTimeout + 60;

        $notes[] = sprintf(
            'Compliance processing lease is calculated from job timeout (%ds) plus a safety floor, not from an arbitrary `queue:work --timeout`. Keep worker --timeout ≥ job timeout, and keep configured lease (%ds) ≥ %ds so it exceeds the maximum time a healthy worker may still execute this job.',
            $jobTimeout,
            $lease,
            $effectiveLeaseFloor,
        );

        if ($lease < $effectiveLeaseFloor) {
            $warnings[] = sprintf(
                'SHOPIFY_COMPLIANCE_PROCESSING_LEASE_SECONDS=%d is below the enforced floor (job timeout %ds + 60). Claim code raises the lease at runtime; raise the configured value to ≥%d so ops config matches runtime.',
                $lease,
                $jobTimeout,
                $effectiveLeaseFloor,
            );
        }

        if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_async_signals')) {
            $warnings[] = 'pcntl alarm support is unavailable. Laravel cannot reliably enforce ProcessShopifyComplianceRequestJob::$timeout; a worker may outlive the processing lease and allow reclaim while side effects still run. Prefer a PHP build with pcntl in staging/production.';
        }

        $connection = (string) config('queue.default', 'sync');
        if ($connection === 'database') {
            $retryAfter = (int) config('queue.connections.database.retry_after', 360);
            if ($retryAfter <= $jobTimeout) {
                $warnings[] = sprintf(
                    'Database queue retry_after=%d must be greater than compliance job timeout %d (and should exceed the longest job on the connection).',
                    $retryAfter,
                    $jobTimeout,
                );
            }
        } elseif ($connection !== 'sync' && $connection !== 'null') {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);
            if ($retryAfter <= $jobTimeout) {
                $warnings[] = sprintf(
                    "Queue connection [%s] retry_after=%d is not safe for ProcessShopifyComplianceRequestJob (timeout %ds). Use the database queue with retry_after > job timeout, or raise this connection's retry_after above %d.",
                    $connection,
                    $retryAfter,
                    $jobTimeout,
                    $jobTimeout,
                );
            } else {
                $warnings[] = sprintf(
                    "Queue connection [%s] is not the database driver. Confirm retry_after=%d remains greater than compliance job timeout %d before staging activation.",
                    $connection,
                    $retryAfter,
                    $jobTimeout,
                );
            }
        }

        if ($log && ! app()->runningUnitTests()) {
            foreach ($warnings as $warning) {
                Log::warning('Shopify compliance runtime configuration: '.$warning);
            }
        }

        return [
            'warnings' => $warnings,
            'notes' => $notes,
        ];
    }
}
