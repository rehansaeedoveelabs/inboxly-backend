<?php

namespace App\Console\Commands;

use App\Actions\Shopify\RecoverStrandedShopifyComplianceRequests;
use Illuminate\Console\Command;

class RecoverStrandedShopifyComplianceRequestsCommand extends Command
{
    protected $signature = 'shopify:recover-compliance-requests
                            {--older-than= : Only redispatch received requests older than this many seconds (default: config)}
                            {--limit= : Maximum requests to redispatch across both buckets (default: config)}';

    protected $description = 'Redispatch stranded Shopify compliance requests (old received + expired processing leases). Not an alerting system.';

    public function handle(RecoverStrandedShopifyComplianceRequests $recover): int
    {
        $olderThan = $this->option('older-than');
        $limit = $this->option('limit');

        $result = $recover->handle(
            olderThanSeconds: $olderThan !== null && $olderThan !== '' ? (int) $olderThan : null,
            limit: $limit !== null && $limit !== '' ? (int) $limit : null,
        );

        $this->info(sprintf(
            'Redispatched %d received + %d expired-processing compliance request(s) (%d dispatch failure(s)).',
            $result['received'],
            $result['expired_processing'],
            $result['dispatch_failures'],
        ));
        $this->comment('This command recovers eligible work only; it is not monitoring or alerting.');

        return $result['dispatch_failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
