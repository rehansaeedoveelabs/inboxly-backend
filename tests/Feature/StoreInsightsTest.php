<?php

namespace Tests\Feature;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class StoreInsightsTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_overview_returns_live_store_metrics(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'live@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Insight campaign',
            'channel' => 'email',
            'subject' => 'Hi',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ])->assertCreated();

        $response = $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/insights/overview")
            ->assertOk()
            ->assertJsonPath('contacts_total', 1)
            ->assertJsonPath('email_subscribers', 1)
            ->assertJsonPath('campaigns.total', 1)
            ->assertJsonPath('campaigns.draft', 1);

        $this->assertIsArray($response->json('audience_series'));
        $this->assertCount(4, $response->json('audience_series'));
        $this->assertIsArray($response->json('recent_campaigns'));
    }

    public function test_overview_requires_store_access(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$outsider] = $this->adminWithWorkspace();

        $this->spa()->actingAs($outsider)
            ->getJson("/api/v1/stores/{$store->id}/insights/overview")
            ->assertForbidden();
    }
}
