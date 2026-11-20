<?php

namespace App\Support\Messaging;

use App\Models\Contact;
use App\Models\Store;

/**
 * Replaces {{merge_tags}} in campaign/automation copy at send time.
 * Tags match the email studio Variables panel.
 */
final class MergeTagPersonalizer
{
    /**
     * @param  array<string, string|null>  $vars
     */
    public function replace(?string $content, array $vars): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        $normalized = [];
        foreach ($vars as $key => $value) {
            $normalized[strtolower((string) $key)] = $value === null ? '' : (string) $value;
        }

        $out = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $matches) use ($normalized): string {
                $key = strtolower($matches[1]);

                return array_key_exists($key, $normalized) ? $normalized[$key] : $matches[0];
            },
            $content,
        );

        $shopName = $normalized['shop_name'] ?? '';
        if ($shopName !== '' && is_string($out)) {
            $out = str_replace('[shop_name]', $shopName, $out);
        }

        return is_string($out) ? $out : $content;
    }

    /**
     * @return array{subject: string, html_body: string, text_body: string}
     */
    public function personalizeMessage(
        ?string $subject,
        ?string $htmlBody,
        ?string $textBody,
        array $vars,
    ): array {
        return [
            'subject' => $this->replace($subject, $vars),
            'html_body' => $this->replace($htmlBody, $vars),
            'text_body' => $this->replace($textBody, $vars),
        ];
    }

    /**
     * Build merge vars for a contact + store (campaign or automation send).
     *
     * @return array<string, string>
     */
    public function varsFor(Store $store, ?Contact $contact = null, ?string $discountCode = null): array
    {
        $brand = is_array($store->metadata['brand'] ?? null) ? $store->metadata['brand'] : [];
        $shopName = '';
        if (isset($brand['shop_name']) && is_string($brand['shop_name']) && trim($brand['shop_name']) !== '') {
            $shopName = trim($brand['shop_name']);
        } elseif (is_string($store->name) && trim($store->name) !== '') {
            $shopName = trim($store->name);
        } elseif (is_string($store->shopify_shop_domain ?? null) && $store->shopify_shop_domain !== '') {
            $shopName = (string) $store->shopify_shop_domain;
        }

        return [
            'shop_name' => $shopName,
            'first_name' => $contact !== null && is_string($contact->first_name)
                ? trim($contact->first_name)
                : '',
            'discount_code' => $discountCode !== null ? trim($discountCode) : '',
            'address' => isset($brand['address']) && is_string($brand['address']) ? trim($brand['address']) : '',
            'city' => isset($brand['city']) && is_string($brand['city']) ? trim($brand['city']) : '',
            'country' => isset($brand['country']) && is_string($brand['country']) ? trim($brand['country']) : '',
        ];
    }
}
