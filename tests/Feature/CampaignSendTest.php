<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\Suppression;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\FakeEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class CampaignSendTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_send_resolves_recipients_and_delivers_via_fake_driver(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $eligible = $this->contactIn($store, ['email' => 'ok@example.com']);
        $eligible->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $unsubscribed = $this->contactIn($store, ['email' => 'no@example.com']);
        $unsubscribed->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Unsubscribed,
        ]);

        Suppression::query()->create([
            'store_id' => $store->id,
            'channel' => 'email',
            'address' => 'blocked@example.com',
            'reason' => SuppressionReason::HardBounce,
            'source' => 'test',
        ]);
        $blocked = $this->contactIn($store, ['email' => 'blocked@example.com']);
        $blocked->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Send me',
            'channel' => 'email',
            'subject' => 'Hello',
            'body' => 'Body copy',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $campaign = Campaign::query()->findOrFail($campaignId);
        $this->assertSame(CampaignStatus::Sent, $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertCount(1, $fake->sent);
        $this->assertSame('ok@example.com', $fake->sent[0]->to);

        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_id' => $campaignId,
            'contact_id' => $eligible->id,
            'status' => CampaignRecipientStatus::Sent->value,
        ]);
    }

    public function test_test_send_targets_authenticated_user(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Test',
            'channel' => 'email',
            'subject' => 'Hi',
            'body' => 'Preview body',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/test-send")
            ->assertOk()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.to', mb_strtolower($admin->email));

        $this->assertCount(1, $fake->sent);
        $this->assertStringStartsWith('[Test] ', $fake->sent[0]->subject);
    }

    public function test_cannot_send_twice(): void
    {
        $this->app->instance(EmailSender::class, new FakeEmailSender);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'once@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Once',
            'channel' => 'email',
            'subject' => 'Hi',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/send")->assertOk();
        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/send")->assertStatus(409);
    }

    public function test_summary_includes_send_buckets(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)->getJson("/api/v1/stores/{$store->id}/campaigns/summary")
            ->assertOk()
            ->assertJsonStructure(['total', 'draft', 'scheduled', 'sending', 'sent', 'failed']);
    }
}
