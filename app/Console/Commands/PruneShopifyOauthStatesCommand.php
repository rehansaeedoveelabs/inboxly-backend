<?php

namespace App\Console\Commands;

use App\Models\ShopifyOauthState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneShopifyOauthStatesCommand extends Command
{
    protected $signature = 'shopify:prune-oauth-states
                            {--batch=500 : Maximum rows deleted per batch}
                            {--consumed-after-hours=24 : Delete consumed rows older than this many hours}';

    protected $description = 'Delete expired or sufficiently old consumed Shopify OAuth state rows';

    public function handle(): int
    {
        $batchSize = max(1, (int) $this->option('batch'));
        $consumedAfterHours = max(0, (int) $this->option('consumed-after-hours'));
        $consumedBefore = now()->subHours($consumedAfterHours);
        $now = now();

        $totalDeleted = 0;

        do {
            $ids = ShopifyOauthState::query()
                ->where(function ($query) use ($now, $consumedBefore) {
                    $query->where('expires_at', '<', $now)
                        ->orWhere(function ($consumed) use ($consumedBefore) {
                            $consumed->whereNotNull('consumed_at')
                                ->where('consumed_at', '<', $consumedBefore);
                        });
                })
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = DB::table('shopify_oauth_states')
                ->whereIn('id', $ids)
                ->delete();

            $totalDeleted += $deleted;
        } while ($ids->count() === $batchSize);

        $this->info("Pruned {$totalDeleted} Shopify OAuth state row(s).");

        return self::SUCCESS;
    }
}
