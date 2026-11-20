<?php

namespace App\Actions\Shopify;

use App\Actions\UpsertContact;
use App\Events\CheckoutAbandoned;
use App\Models\ShopifyAbandonedCheckout;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * Upserts abandoned checkout rows from Shopify checkouts/* webhooks
 * and enrolls cart-recovery automations when a contact email is present.
 */
class ProcessShopifyCheckoutWebhook
{
    public function __construct(private UpsertContact $upsertContact) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Store $store, array $payload, string $topic): void
    {
        $checkoutId = $this->checkoutId($payload);
        if ($checkoutId === null) {
            Log::notice('Shopify checkout webhook missing id.', [
                'store_id' => $store->getKey(),
                'topic' => $topic,
            ]);

            return;
        }

        $completedAt = $this->nullableString($payload['completed_at'] ?? null);
        $email = $this->nullableEmail($payload['email'] ?? null)
            ?? $this->nullableEmail(data_get($payload, 'customer.email'));
        $token = $this->nullableString($payload['token'] ?? null);
        $url = $this->nullableString($payload['abandoned_checkout_url'] ?? null);
        $currency = $this->nullableString($payload['currency'] ?? null);
        $total = $this->nullableMoney($payload['total_price'] ?? null);
        $abandonedAt = $this->parseTimestamp($payload['updated_at'] ?? $payload['created_at'] ?? null);

        $contactId = null;
        if ($email !== null) {
            $contact = $this->upsertContact->handle($store, [
                'email' => $email,
                'first_name' => $this->nullableString(data_get($payload, 'customer.first_name') ?? data_get($payload, 'billing_address.first_name')),
                'last_name' => $this->nullableString(data_get($payload, 'customer.last_name') ?? data_get($payload, 'billing_address.last_name')),
                'source' => 'shopify_checkout',
            ]);
            $contactId = $contact->getKey();
        }

        $existing = ShopifyAbandonedCheckout::query()
            ->where('store_id', $store->getKey())
            ->where('shopify_checkout_id', $checkoutId)
            ->first();

        $recoveredAt = $completedAt !== null
            ? ($this->parseTimestamp($completedAt) ?? now())
            : $existing?->recovered_at;

        $row = ShopifyAbandonedCheckout::query()->updateOrCreate(
            [
                'store_id' => $store->getKey(),
                'shopify_checkout_id' => $checkoutId,
            ],
            [
                'checkout_token' => $token,
                'contact_id' => $contactId ?? $existing?->contact_id,
                'email' => $email ?? $existing?->email,
                'abandoned_checkout_url' => $url ?? $existing?->abandoned_checkout_url,
                'total_price' => $total ?? $existing?->total_price,
                'currency_code' => $currency ?? $existing?->currency_code,
                'abandoned_at' => $existing?->abandoned_at ?? ($abandonedAt ?? now()),
                'recovered_at' => $recoveredAt,
                'enrolled_at' => $existing?->enrolled_at,
                'metadata' => array_filter([
                    'line_item_count' => is_array($payload['line_items'] ?? null)
                        ? count($payload['line_items'])
                        : null,
                    'topic' => $topic,
                ], static fn ($v) => $v !== null),
            ],
        );

        if ($row->isRecovered()) {
            return;
        }

        if ($contactId === null || $row->enrolled_at !== null) {
            return;
        }

        // Mark enrolled before dispatch so duplicate webhook deliveries do not double-enroll.
        $row->forceFill(['enrolled_at' => now()])->save();

        event(new CheckoutAbandoned($contactId, $store->getKey(), $row->getKey()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function checkoutId(array $payload): ?string
    {
        $id = $payload['id'] ?? null;
        if (is_int($id) || is_float($id)) {
            return (string) $id;
        }
        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableEmail(mixed $value): ?string
    {
        $email = $this->nullableString($value);
        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return mb_strtolower($email);
    }

    private function nullableMoney(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }
        if (is_string($value) && is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        return null;
    }

    private function parseTimestamp(mixed $value): ?\Carbon\CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
