<?php

namespace App\Actions\Shopify\Compliance;

use App\Enums\ShopifyComplianceStatus;
use App\Models\Contact;
use App\Models\ShopifyComplianceRequest;
use App\Models\Suppression;
use App\Support\Shopify\ShopifyComplianceCorrelation;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProcessCustomersDataRequest
{
    /**
     * Correlation is read here but never deleted. Cleanup happens only after successful
     * owned finalization in ProcessShopifyComplianceRequestJob so a crash mid-run can replay.
     *
     * @return array{status: ShopifyComplianceStatus, checkpoint: array<string, mixed>, legal_checklist: array<string, mixed>, inventory_summary: array<string, mixed>, clear_correlation: bool}
     */
    public function handle(ShopifyComplianceRequest $request): array
    {
        $checkpoint = is_array($request->processing_checkpoint) ? $request->processing_checkpoint : [];
        $legal = is_array($request->legal_checklist) ? $request->legal_checklist : [];

        $checkpoint['resolve_store'] = [
            'done' => true,
            'store_found' => $request->store_id !== null,
        ];

        if (! isset($checkpoint['build_inventory']['done'])) {
            $correlation = $this->requireCorrelationIfPresent($request);
            $summary = $this->buildInventory($request, $correlation);
            $checkpoint['build_inventory'] = ['done' => true, 'at' => now()->toIso8601String()];

            $legal['merchant_delivery'] = 'out_of_band';

            return [
                'status' => ShopifyComplianceStatus::Completed,
                'checkpoint' => $checkpoint,
                'legal_checklist' => $legal,
                'inventory_summary' => $summary,
                'clear_correlation' => true,
            ];
        }

        $legal['merchant_delivery'] = 'out_of_band';

        return [
            'status' => ShopifyComplianceStatus::Completed,
            'checkpoint' => $checkpoint,
            'legal_checklist' => $legal,
            'inventory_summary' => is_array($request->inventory_summary) ? $request->inventory_summary : [],
            'clear_correlation' => true,
        ];
    }

    /**
     * If a correlation pointer exists it must be readable — otherwise refuse to complete
     * with an empty/incomplete inventory after a lost correlation file.
     *
     * @return array{shopify_customer_id: ?string, email: ?string, phone: ?string}|null
     */
    private function requireCorrelationIfPresent(ShopifyComplianceRequest $request): ?array
    {
        if (! filled($request->correlation_path) && ! filled($request->correlation_disk)) {
            return null;
        }

        $correlation = ShopifyComplianceCorrelation::read($request);
        if ($correlation === null) {
            throw new RuntimeException('compliance_correlation_unreadable');
        }

        return $correlation;
    }

    /**
     * @param  array{shopify_customer_id: ?string, email: ?string, phone: ?string}|null  $correlation
     * @return array<string, mixed>
     */
    private function buildInventory(ShopifyComplianceRequest $request, ?array $correlation): array
    {
        $contactIds = [];
        $email = $correlation['email'] ?? null;
        $phone = $correlation['phone'] ?? null;

        if ($request->store_id !== null) {
            $query = Contact::withTrashed()->where('store_id', $request->store_id);
            if (filled($email) || filled($phone)) {
                $query->where(function ($q) use ($email, $phone) {
                    if (filled($email)) {
                        $normalized = Contact::normalizeEmail($email);
                        if ($normalized !== null) {
                            $q->orWhere('email', $normalized);
                        }
                    }
                    if (filled($phone)) {
                        $normalizedPhone = Contact::normalizePhone($phone);
                        if ($normalizedPhone !== null) {
                            $q->orWhere('phone', $normalizedPhone);
                        }
                    }
                });
                $contactIds = $query->limit(100)->pluck('id')->all();
            }
        }

        $suppressionCount = 0;
        if ($request->store_id !== null && (filled($email) || filled($phone))) {
            $suppressionCount = Suppression::query()
                ->where('store_id', $request->store_id)
                ->where(function ($q) use ($email, $phone) {
                    if (filled($email)) {
                        $q->orWhere('address', Contact::normalizeEmail($email));
                    }
                    if (filled($phone)) {
                        $q->orWhere('address', Contact::normalizePhone($phone));
                    }
                })
                ->count();
        }

        $artifact = [
            'generated_at' => now()->toIso8601String(),
            'topic' => $request->topic->value,
            'shop_domain' => $request->shop_domain,
            'shopify_customer_id' => $request->shopify_customer_id,
            'shopify_data_request_id' => $request->shopify_data_request_id,
            'store_id' => $request->store_id,
            'matched_contact_ids' => array_values($contactIds),
            'matched_contact_count' => count($contactIds),
            'suppression_match_count' => $suppressionCount,
            'notes' => [
                'Shopify-derived identity tables are not implemented in M1.',
                'Merchant delivery of this artifact is out-of-band.',
            ],
        ];

        $disk = 'local';
        $path = 'compliance/'.$request->getKey().'/inventory.json';
        $written = Storage::disk($disk)->put(
            $path,
            json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        if (! $written || ! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('compliance_inventory_write_failed');
        }

        $request->forceFill([
            'inventory_disk' => $disk,
            'inventory_path' => $path,
        ])->save();

        return [
            'matched_contact_count' => count($contactIds),
            'suppression_match_count' => $suppressionCount,
            'store_found' => $request->store_id !== null,
            'artifact_verified' => true,
        ];
    }
}
