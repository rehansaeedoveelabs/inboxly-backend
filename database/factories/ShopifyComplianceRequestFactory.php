<?php

namespace Database\Factories;

use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Models\ShopifyComplianceRequest;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShopifyComplianceRequest>
 */
class ShopifyComplianceRequestFactory extends Factory
{
    protected $model = ShopifyComplianceRequest::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'workspace_id' => null,
            'shop_domain' => fake()->unique()->domainWord().'.myshopify.com',
            'topic' => ShopifyComplianceTopic::CustomersDataRequest,
            'webhook_id' => (string) Str::uuid(),
            'shopify_customer_id' => (string) fake()->numerify('########'),
            'shopify_data_request_id' => (string) fake()->numerify('####'),
            'payload_sha256' => hash('sha256', 'factory-compliance-payload'),
            'status' => ShopifyComplianceStatus::Received,
            'processing_checkpoint' => null,
            'legal_checklist' => null,
            'attempt_count' => 0,
            'error_code' => null,
            'error_message' => null,
            'inventory_disk' => null,
            'inventory_path' => null,
            'correlation_disk' => null,
            'correlation_path' => null,
            'inventory_summary' => null,
            'received_at' => now(),
            'processing_started_at' => null,
            'processing_lease_expires_at' => null,
            'processing_lease_token' => null,
            'completed_at' => null,
            'failed_at' => null,
            'awaiting_policy_at' => null,
        ];
    }

    public function withoutStore(): static
    {
        return $this->state(fn () => ['store_id' => null]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ShopifyComplianceRequest $request) {
            if ($request->workspace_id !== null) {
                return;
            }
            if ($request->store_id instanceof Store) {
                $request->workspace_id = $request->store_id->workspace_id;

                return;
            }
            if (is_string($request->store_id) && $request->store_id !== '') {
                $store = Store::query()->find($request->store_id);
                if ($store !== null) {
                    $request->workspace_id = $store->workspace_id;
                }
            }
        })->afterCreating(function (ShopifyComplianceRequest $request) {
            if ($request->workspace_id !== null || $request->store_id === null) {
                return;
            }
            $store = Store::query()->find($request->store_id);
            if ($store !== null) {
                $request->forceFill(['workspace_id' => $store->workspace_id])->saveQuietly();
            }
        });
    }

    public function customersRedact(): static
    {
        return $this->state(fn () => [
            'topic' => ShopifyComplianceTopic::CustomersRedact,
            'shopify_data_request_id' => null,
        ]);
    }

    public function shopRedact(): static
    {
        return $this->state(fn () => [
            'topic' => ShopifyComplianceTopic::ShopRedact,
            'shopify_customer_id' => null,
            'shopify_data_request_id' => null,
        ]);
    }

    public function status(ShopifyComplianceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
