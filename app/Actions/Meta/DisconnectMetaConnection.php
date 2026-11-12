<?php

namespace App\Actions\Meta;

use App\Models\MetaConnection;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DisconnectMetaConnection
{
    /**
     * Always clears local Meta credentials and selected Pages for the store.
     * Meta does not provide a Shopify-style app uninstall revoke in this flow;
     * users revoke app access in Facebook settings. We never log tokens.
     *
     * @return array{store: Store, disconnected: bool}
     */
    public function handle(Store $store): array
    {
        $store->refresh();

        if ($store->is_demo) {
            return ['store' => $store, 'disconnected' => false];
        }

        $existed = false;

        DB::transaction(function () use ($store, &$existed) {
            /** @var MetaConnection|null $connection */
            $connection = MetaConnection::query()
                ->where('store_id', $store->getKey())
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $existed = true;
            $connection->pages()->delete();
            $connection->delete();
        });

        if ($existed) {
            Log::info('Meta connection disconnected.', [
                'store_id' => $store->getKey(),
            ]);
        }

        return [
            'store' => $store->fresh(),
            'disconnected' => $existed,
        ];
    }
}
