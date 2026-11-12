<?php

namespace App\Actions\Shopify\Compliance;

use App\Enums\ShopifyComplianceStatus;
use App\Models\Contact;
use App\Models\ShopifyComplianceRequest;
use App\Support\Shopify\ShopifyComplianceCorrelation;
use RuntimeException;

class ProcessCustomersRedact
{
    /**
     * Correlation is retained until owned finalization clears it (job). No contact/consent deletes in M1.
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

        $correlation = $this->requireCorrelationIfPresent($request);
        $matched = 0;
        if ($request->store_id !== null && $correlation !== null) {
            $email = $correlation['email'] ?? null;
            $phone = $correlation['phone'] ?? null;
            if (filled($email) || filled($phone)) {
                $matched = Contact::withTrashed()
                    ->where('store_id', $request->store_id)
                    ->where(function ($q) use ($email, $phone) {
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
                    })
                    ->count();
            }
        }

        $checkpoint['inventory_linked_records'] = [
            'done' => true,
            'matched_contact_count' => $matched,
        ];

        // Approved: do not erase linked Inboxly contacts or consent history in M1.
        $checkpoint['erase_linked_contacts'] = ['done' => false, 'skipped' => 'awaiting_policy'];
        $checkpoint['erase_consent_history'] = ['done' => false, 'skipped' => 'awaiting_policy'];
        $checkpoint['erase_shopify_derived'] = [
            'done' => true,
            'note' => 'no_shopify_customer_identity_table_in_m1',
        ];

        $legal['linked_contact_erase'] = 'pending_policy';
        $legal['consent_erase'] = 'pending_policy';
        $legal['gid_retention'] = 'pending_policy';

        return [
            'status' => ShopifyComplianceStatus::AwaitingPolicy,
            'checkpoint' => $checkpoint,
            'legal_checklist' => $legal,
            'inventory_summary' => [
                'matched_contact_count' => $matched,
                'contacts_erased' => 0,
                'consents_erased' => 0,
                'consent_events_erased' => 0,
            ],
            'clear_correlation' => true,
        ];
    }

    /**
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
}
