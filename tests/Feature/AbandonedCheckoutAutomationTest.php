<?php

namespace Tests\Feature;

use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStatus;
use App\Enums\AutomationTriggerType;
use App\Models\Automation;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStep;
use App\Models\Contact;
use App\Models\ShopifyAbandonedCheckout;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class AbandonedCheckoutAutomationTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, string>}
     */
    private function signedWebhook(
        array $payload,
        string $topic,
        string $shop,
        string $webhookId,
    ): array {
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $hmac = base64_encode(hash_hmac(
            'sha256',
            $rawBody,
            (string) config('services.shopify.app_secret'),
            true,
        ));

        return [$rawBody, [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_API_VERSION' => '2026-10',
        ]];
    }

    public function test_checkout_webhook_upserts_contact_and_enrolls_abandoned_cart_automation(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('cart-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
        ]);

        $automation = Automation::factory()->create([
            'store_id' => $store->id,
            'status' => AutomationStatus::Active,
            'trigger_type' => AutomationTriggerType::CheckoutAbandoned,
            'name' => 'Cart recovery',
        ]);
        AutomationStep::factory()->delay(60, 0)->create([
            'automation_id' => $automation->id,
        ]);
        AutomationStep::factory()->sendEmail('Come back', 'Finish checkout', 1)->create([
            'automation_id' => $automation->id,
        ]);

        [$body, $headers] = $this->signedWebhook([
            'id' => 555001,
            'token' => 'tok_cart_1',
            'email' => 'shopper@example.com',
            'abandoned_checkout_url' => 'https://cart-shop.myshopify.com/checkouts/tok_cart_1',
            'currency' => 'USD',
            'total_price' => '42.00',
            'created_at' => '2026-11-20T10:00:00Z',
            'updated_at' => '2026-11-20T10:05:00Z',
            'completed_at' => null,
            'line_items' => [['id' => 1], ['id' => 2]],
            'customer' => ['first_name' => 'Sam', 'last_name' => 'Shopper'],
        ], 'checkouts/create', 'cart-shop.myshopify.com', 'checkout-delivery-1');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $contact = Contact::query()->where('store_id', $store->id)->where('email', 'shopper@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Sam', $contact->first_name);
        $this->assertSame('shopify_checkout', $contact->source);

        $checkout = ShopifyAbandonedCheckout::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($checkout);
        $this->assertSame('555001', $checkout->shopify_checkout_id);
        $this->assertSame($contact->id, $checkout->contact_id);
        $this->assertNotNull($checkout->enrolled_at);
        $this->assertNull($checkout->recovered_at);

        $enrollment = AutomationEnrollment::query()
            ->where('automation_id', $automation->id)
            ->where('contact_id', $contact->id)
            ->first();
        $this->assertNotNull($enrollment);
        $this->assertSame(AutomationEnrollmentStatus::Active, $enrollment->status);
    }

    public function test_completed_checkout_marks_recovered_without_enrolling(): void
    {
        Queue::fake();

        [, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('done-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
        ]);

        Automation::factory()->create([
            'store_id' => $store->id,
            'status' => AutomationStatus::Active,
            'trigger_type' => AutomationTriggerType::CheckoutAbandoned,
        ]);

        [$body, $headers] = $this->signedWebhook([
            'id' => 555002,
            'email' => 'done@example.com',
            'completed_at' => '2026-11-20T11:00:00Z',
            'updated_at' => '2026-11-20T11:00:00Z',
        ], 'checkouts/update', 'done-shop.myshopify.com', 'checkout-delivery-2');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $checkout = ShopifyAbandonedCheckout::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($checkout);
        $this->assertNotNull($checkout->recovered_at);
        $this->assertNull($checkout->enrolled_at);
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }
}
