<?php

namespace Tests\Feature;

use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\EmailOtp;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingOtpAndShopifyInstallTest extends TestCase
{
    use RefreshDatabase;

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sam Merchant',
            'email' => 'sam@example.com',
            'phone' => '+15555550999',
            'shop_name' => 'Sam Store',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedCallbackQuery(array $overrides = []): array
    {
        $params = array_merge([
            'code' => 'shopify-auth-code',
            'shop' => 'sam-store.myshopify.com',
            'state' => 'test-state',
            'timestamp' => (string) now()->timestamp,
        ], $overrides);

        unset($params['hmac']);
        ksort($params);
        $message = collect($params)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode('&');
        $params['hmac'] = hash_hmac('sha256', $message, (string) config('services.shopify.app_secret'));

        return $params;
    }

    public function test_otp_verify_returns_shopify_install_url(): void
    {
        Notification::fake();

        $this->spa()->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $user = User::firstWhere('email', 'sam@example.com');
        $this->assertNotNull($user);

        EmailOtp::query()->where('user_id', $user->id)->update([
            'code_hash' => Hash::make('123456'),
        ]);

        $response = $this->spa()->actingAs($user)
            ->postJson('/api/v1/auth/email/otp/verify', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.user.email_verified', true);

        $authorizeUrl = $response->json('data.shopify_authorize_url');
        $this->assertIsString($authorizeUrl);
        $this->assertStringStartsWith('https://admin.shopify.com/oauth/install?client_id=', $authorizeUrl);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('shopify_oauth_states', [
            'user_id' => $user->id,
            'shop_domain' => null,
        ]);
    }

    public function test_continue_install_binds_shop_and_returns_authorize_url(): void
    {
        Notification::fake();

        $this->spa()->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $user = User::firstWhere('email', 'sam@example.com');
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->spa()->actingAs($user)
            ->getJson('/api/v1/shopify/oauth/install?json=1')
            ->assertOk();

        $state = ShopifyOauthState::query()->where('user_id', $user->id)->latest('created_at')->first();
        $this->assertNotNull($state);
        $this->assertNull($state->shop_domain);

        $response = $this->spa()->actingAs($user)
            ->getJson('/api/v1/shopify/oauth/continue?shop=sam-store.myshopify.com')
            ->assertOk();

        $url = $response->json('data.authorize_url');
        $this->assertIsString($url);
        $this->assertStringContainsString('https://sam-store.myshopify.com/admin/oauth/authorize?', $url);
        $this->assertStringContainsString('state='.$state->state, $url);
        $this->assertSame('sam-store.myshopify.com', $state->fresh()->shop_domain);
    }

    public function test_callback_connects_pending_store_from_null_shop_state(): void
    {
        Queue::fake();
        Notification::fake();

        $this->spa()->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $user = User::firstWhere('email', 'sam@example.com');
        $user->forceFill(['email_verified_at' => now()])->save();
        $store = Store::query()->firstOrFail();

        $state = ShopifyOauthState::query()->create([
            'state' => 'state-onboarding-1',
            'user_id' => $user->id,
            'workspace_id' => $store->workspace_id,
            'store_id' => $store->id,
            'shop_domain' => null,
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::fake([
            'https://sam-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_access',
                'refresh_token' => 'shprt_refresh',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 86400,
                'scope' => 'read_products,read_customers,read_orders,read_all_orders',
            ], 200),
            'https://sam-store.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'shop' => [
                        'id' => 'gid://shopify/Shop/1',
                        'name' => 'Sam Store',
                        'myshopifyDomain' => 'sam-store.myshopify.com',
                        'currencyCode' => 'USD',
                        'ianaTimezone' => 'UTC',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ));

        $response->assertRedirect();
        $this->assertStringContainsString('shopify=connected', (string) $response->headers->get('Location'));

        $store->refresh();
        $this->assertSame('connected', $store->connection_status->value);
        $this->assertSame('sam-store.myshopify.com', $store->domain);
        $this->assertSame('sam-store.myshopify.com', $store->shopify_shop_domain);
        Queue::assertPushed(RegisterShopifyWebhooksJob::class);
    }

    public function test_wrong_otp_is_rejected(): void
    {
        Notification::fake();
        $this->spa()->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $user = User::firstWhere('email', 'sam@example.com');

        EmailOtp::query()->where('user_id', $user->id)->update([
            'code_hash' => Hash::make('123456'),
        ]);

        $this->spa()->actingAs($user)
            ->postJson('/api/v1/auth/email/otp/verify', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }
}
