<?php

namespace Tests\Feature;

use App\Models\ShopifyOauthState;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class PruneShopifyOauthStatesTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    private function makeState(array $overrides = []): ShopifyOauthState
    {
        [$user, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);

        return ShopifyOauthState::query()->create(array_merge([
            'state' => bin2hex(random_bytes(32)),
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => $store->domain,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ], $overrides));
    }

    public function test_expired_rows_are_removed(): void
    {
        $expired = $this->makeState([
            'expires_at' => now()->subMinutes(5),
        ]);
        $valid = $this->makeState([
            'expires_at' => now()->addMinutes(10),
        ]);

        Artisan::call('shopify:prune-oauth-states');

        $this->assertDatabaseMissing('shopify_oauth_states', ['id' => $expired->id]);
        $this->assertDatabaseHas('shopify_oauth_states', ['id' => $valid->id]);
    }

    public function test_old_consumed_rows_are_removed(): void
    {
        $oldConsumed = $this->makeState([
            'expires_at' => now()->addHour(),
            'consumed_at' => now()->subHours(25),
        ]);
        $recentConsumed = $this->makeState([
            'expires_at' => now()->addHour(),
            'consumed_at' => now()->subHours(2),
        ]);

        Artisan::call('shopify:prune-oauth-states', ['--consumed-after-hours' => 24]);

        $this->assertDatabaseMissing('shopify_oauth_states', ['id' => $oldConsumed->id]);
        $this->assertDatabaseHas('shopify_oauth_states', ['id' => $recentConsumed->id]);
    }

    public function test_valid_unexpired_rows_remain(): void
    {
        $valid = $this->makeState([
            'expires_at' => now()->addMinutes(30),
            'consumed_at' => null,
        ]);

        Artisan::call('shopify:prune-oauth-states');
        Artisan::call('shopify:prune-oauth-states');

        $this->assertDatabaseHas('shopify_oauth_states', [
            'id' => $valid->id,
            'consumed_at' => null,
        ]);
    }

    public function test_repeated_cleanup_is_harmless(): void
    {
        $expired = $this->makeState([
            'expires_at' => now()->subMinute(),
        ]);

        Artisan::call('shopify:prune-oauth-states');
        $this->assertDatabaseMissing('shopify_oauth_states', ['id' => $expired->id]);

        $exit = Artisan::call('shopify:prune-oauth-states');
        $this->assertSame(0, $exit);
        $this->assertDatabaseCount('shopify_oauth_states', 0);
    }
}
