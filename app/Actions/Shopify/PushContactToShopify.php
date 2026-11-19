<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyCustomerIdentityLinkSource;
use App\Enums\ShopifyCustomerIdentityStatus;
use App\Exceptions\ShopifyAdminGraphqlException;
use App\Models\Contact;
use App\Models\ShopifyCustomerIdentity;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use App\Services\Shopify\ShopifyCredentialService;
use App\Support\Shopify\ShopifyCommerceGraphqlMutations;
use Illuminate\Support\Facades\Log;

class PushContactToShopify
{
    public function __construct(
        private ShopifyCredentialService $credentials,
        private ShopifyAdminGraphqlClient $graphql,
    ) {}

    public function handle(Contact $contact): void
    {
        $contact->loadMissing('store');
        $store = $contact->store;
        if ($store === null || ! $store->isConnected() || ! $store->hasShopifyCredentials()) {
            return;
        }

        if (! in_array('write_customers', $store->grantedShopifyScopes(), true)) {
            Log::info('Skipping Shopify contact write-back; write_customers scope missing.', [
                'store_id' => $store->getKey(),
                'contact_id' => $contact->getKey(),
            ]);

            return;
        }

        $identity = ShopifyCustomerIdentity::query()
            ->where('store_id', $store->getKey())
            ->where('contact_id', $contact->getKey())
            ->whereNull('shopify_deleted_at')
            ->first();

        if ($identity === null || ! is_string($identity->shopify_customer_gid) || $identity->shopify_customer_gid === '') {
            return;
        }

        $input = [
            'id' => $identity->shopify_customer_gid,
        ];
        if (is_string($contact->first_name) && $contact->first_name !== '') {
            $input['firstName'] = $contact->first_name;
        }
        if (is_string($contact->last_name) && $contact->last_name !== '') {
            $input['lastName'] = $contact->last_name;
        }
        if (is_string($contact->email) && $contact->email !== '') {
            $input['email'] = $contact->email;
        }
        if (is_string($contact->phone) && $contact->phone !== '') {
            $input['phone'] = $contact->phone;
        }

        try {
            $token = $this->credentials->ensureValidAccessToken($store);
            $payload = $this->graphql->request(
                (string) $store->shopify_shop_domain,
                $token,
                ShopifyCommerceGraphqlMutations::CUSTOMER_UPDATE,
                ['input' => $input],
            );
            $errors = $payload['data']['customerUpdate']['userErrors'] ?? [];
            if (is_array($errors) && $errors !== []) {
                Log::warning('Shopify customerUpdate returned userErrors.', [
                    'store_id' => $store->getKey(),
                    'contact_id' => $contact->getKey(),
                    'errors' => $errors,
                ]);

                return;
            }

            $identity->forceFill([
                'status' => ShopifyCustomerIdentityStatus::Tracked,
                'link_source' => ShopifyCustomerIdentityLinkSource::InboxlyWriteback,
                'linked_at' => $identity->linked_at ?? now(),
                'last_synced_at' => now(),
                'sync_error_code' => null,
            ])->save();
        } catch (ShopifyAdminGraphqlException $e) {
            Log::warning('Shopify contact write-back failed.', [
                'store_id' => $store->getKey(),
                'contact_id' => $contact->getKey(),
                'code' => $e->errorCode ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
