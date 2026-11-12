<?php

namespace App\Actions\Shopify\Compliance;

use App\Actions\Shopify\PersistShopifyComplianceCheckpoint;
use App\Enums\ShopifyComplianceStatus;
use App\Models\ContactExport;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;

class ProcessShopRedact
{
    public function __construct(
        private readonly PersistShopifyComplianceCheckpoint $persistCheckpoint,
    ) {}

    /**
     * In-app shop/redact only. Side effects are gated by durable checkpoints persisted under the
     * processing lease so a crash mid-run can resume without repeating completed work misleadingly.
     *
     * Idempotency notes:
     * - clear_credentials: disconnectShopify is safe to re-run (already-null tokens → no-op).
     * - purge_oauth_states: delete is idempotent (0 rows on replay).
     * - purge_contact_exports: delete files/rows is idempotent once gone.
     *
     * @return array{status: ShopifyComplianceStatus, checkpoint: array<string, mixed>, legal_checklist: array<string, mixed>, inventory_summary: array<string, mixed>, clear_correlation: bool}
     */
    public function handle(ShopifyComplianceRequest $request, string $leaseToken): array
    {
        $checkpoint = is_array($request->processing_checkpoint) ? $request->processing_checkpoint : [];
        $legal = is_array($request->legal_checklist) ? $request->legal_checklist : [];

        $store = $this->resolveStore($request);

        if (! isset($checkpoint['resolve_store']['done'])) {
            $checkpoint['resolve_store'] = [
                'done' => true,
                'store_found' => $store !== null,
                'store_trashed' => $store?->trashed() ?? false,
            ];
            $request = $this->persistCheckpoint->handle($request, $leaseToken, $checkpoint);
        }

        $credentialsCleared = (bool) ($checkpoint['clear_credentials']['cleared'] ?? false);
        if (! isset($checkpoint['clear_credentials']['done'])) {
            $credentialsCleared = false;
            if ($store !== null && ! $store->trashed()) {
                if ($store->shopify_access_token !== null || $store->shopify_refresh_token !== null) {
                    $store->disconnectShopify();
                    $credentialsCleared = true;
                }
            }
            $checkpoint['clear_credentials'] = [
                'done' => true,
                'cleared' => $credentialsCleared,
            ];
            $request = $this->persistCheckpoint->handle($request, $leaseToken, $checkpoint);
        }

        $oauthDeleted = (int) ($checkpoint['purge_oauth_states']['deleted_count'] ?? 0);
        if (! isset($checkpoint['purge_oauth_states']['done'])) {
            $oauthDeleted = 0;
            if ($store !== null) {
                $oauthDeleted = ShopifyOauthState::query()
                    ->where('store_id', $store->getKey())
                    ->delete();
            }
            $checkpoint['purge_oauth_states'] = [
                'done' => true,
                'deleted_count' => $oauthDeleted,
            ];
            $request = $this->persistCheckpoint->handle($request, $leaseToken, $checkpoint);
        }

        $exportsRemoved = (int) ($checkpoint['purge_contact_exports']['deleted_count'] ?? 0);
        if (! isset($checkpoint['purge_contact_exports']['done'])) {
            $exportsRemoved = 0;
            if ($store !== null) {
                ContactExport::query()
                    ->where('store_id', $store->getKey())
                    ->orderBy('id')
                    ->chunkById(100, function ($exports) use (&$exportsRemoved) {
                        foreach ($exports as $export) {
                            /** @var ContactExport $export */
                            if (filled($export->path) && filled($export->disk)) {
                                try {
                                    Storage::disk($export->disk)->delete($export->path);
                                } catch (\Throwable) {
                                    // Best-effort.
                                }
                            }
                            $export->delete();
                            $exportsRemoved++;
                        }
                    });
            }
            $checkpoint['purge_contact_exports'] = [
                'done' => true,
                'deleted_count' => $exportsRemoved,
            ];
            $this->persistCheckpoint->handle($request, $leaseToken, $checkpoint);
        }

        $legal['external_backups'] = 'ops_responsibility';
        $legal['external_log_archives'] = 'ops_responsibility';
        $legal['organization_wide_erasure'] = 'not_claimed';

        return [
            'status' => ShopifyComplianceStatus::Completed,
            'checkpoint' => $checkpoint,
            'legal_checklist' => $legal,
            'inventory_summary' => [
                'store_found' => $store !== null,
                'credentials_cleared' => (bool) ($checkpoint['clear_credentials']['cleared'] ?? false),
                'oauth_states_deleted' => (int) ($checkpoint['purge_oauth_states']['deleted_count'] ?? 0),
                'exports_deleted' => (int) ($checkpoint['purge_contact_exports']['deleted_count'] ?? 0),
                'external_backups_completed' => false,
            ],
            'clear_correlation' => false,
        ];
    }

    private function resolveStore(ShopifyComplianceRequest $request): ?Store
    {
        $store = null;
        if ($request->store_id !== null) {
            $store = Store::withTrashed()->find($request->store_id);
        }
        if ($store === null) {
            $store = Store::query()
                ->where('shopify_shop_domain', $request->shop_domain)
                ->first();
        }

        return $store;
    }
}
