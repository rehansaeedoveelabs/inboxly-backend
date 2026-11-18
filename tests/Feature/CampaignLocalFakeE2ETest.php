<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\SuppressionReason;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Suppression;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\FakeEmailSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * Executes the local fake-driver campaign workflow over HTTP + scheduler command.
 * Does not enable Brevo or touch production/dev databases.
 */
class CampaignLocalFakeE2ETest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_phpunit_uses_fake_email_driver_on_inboxly_testing(): void
    {
        $this->assertSame('inboxly_testing', config('database.connections.mysql.database'));
        $this->assertSame('fake', config('services.messaging.email_driver'));
        $this->assertFalse((bool) config('services.brevo.sending_enabled'));
    }

    public function test_full_local_fake_driver_workflow_create_schedule_unschedule_dispatch_and_send(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        [$outsiderAdmin, $otherWorkspace] = $this->adminWithWorkspace();
        $foreignStore = $this->storeIn($otherWorkspace);

        $eligible = $this->contactIn($store, ['email' => 'eligible@example.com']);
        $eligible->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $unsubscribed = $this->contactIn($store, ['email' => 'unsub@example.com']);
        $unsubscribed->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Unsubscribed,
        ]);

        Suppression::query()->create([
            'store_id' => $store->id,
            'channel' => 'email',
            'address' => 'suppressed@example.com',
            'reason' => SuppressionReason::Complaint,
            'source' => 'e2e',
        ]);
        $suppressed = $this->contactIn($store, ['email' => 'suppressed@example.com']);
        $suppressed->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $foreignEligible = $this->contactIn($foreignStore, ['email' => 'other-store@example.com']);
        $foreignEligible->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        // 1–4 Create draft, compose, save (update), audience preview.
        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Local E2E',
            'channel' => 'email',
            'audience_type' => 'all_eligible',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->spa()->actingAs($admin)->putJson("/api/v1/campaigns/{$campaignId}", [
            'subject' => 'E2E subject',
            'preview_text' => 'Preview',
            'body' => 'Hello from local fake driver',
        ])->assertOk()
            ->assertJsonPath('data.subject', 'E2E subject')
            ->assertJsonPath('data.body', 'Hello from local fake driver');

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns/audience-preview", [
            'channel' => 'email',
            'audience_type' => 'all_eligible',
        ])->assertOk()->assertJsonPath('count', 1);

        // Cross-tenant isolation.
        $this->spa()->actingAs($outsiderAdmin)->getJson("/api/v1/campaigns/{$campaignId}")
            ->assertForbidden();
        $this->spa()->actingAs($outsiderAdmin)->postJson("/api/v1/campaigns/{$campaignId}/send")
            ->assertForbidden();

        // 5–6 Schedule then unschedule.
        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/schedule", [
            'scheduled_at' => now('UTC')->addHours(3)->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.audience_count', 1);

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/unschedule")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scheduled_at', null);

        // Re-schedule in the past via DB so campaigns:dispatch-due can claim it.
        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/schedule", [
            'scheduled_at' => now('UTC')->addMinutes(30)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');

        Campaign::query()->whereKey($campaignId)->update([
            'scheduled_at' => now('UTC')->subMinute(),
        ]);

        $exit = Artisan::call('campaigns:dispatch-due', ['--limit' => 10]);
        $this->assertSame(0, $exit);

        $campaign = Campaign::query()->findOrFail($campaignId);
        $this->assertSame(CampaignStatus::Sent, $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        // Audience query excludes unsubscribed/suppressed before rows are created.
        $this->assertSame(0, $campaign->skipped_count);
        $this->assertSame(1, $campaign->audience_count);

        $this->assertCount(1, $fake->sent);
        $this->assertSame('eligible@example.com', $fake->sent[0]->to);
        $this->assertSame('E2E subject', $fake->sent[0]->subject);

        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_id' => $campaignId,
            'contact_id' => $eligible->id,
            'status' => CampaignRecipientStatus::Sent->value,
        ]);
        $this->assertDatabaseMissing('campaign_recipients', [
            'campaign_id' => $campaignId,
            'contact_id' => $unsubscribed->id,
        ]);
        $this->assertDatabaseMissing('campaign_recipients', [
            'campaign_id' => $campaignId,
            'contact_id' => $suppressed->id,
        ]);

        $recipientAddresses = CampaignRecipient::query()
            ->where('campaign_id', $campaignId)
            ->pluck('address')
            ->all();
        $this->assertContains('eligible@example.com', $recipientAddresses);
        $this->assertNotContains('other-store@example.com', $recipientAddresses);

        // Immediate send path also works on a fresh draft (fake driver, sync queue).
        $secondId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Send now E2E',
            'channel' => 'email',
            'subject' => 'Now',
            'body' => 'Immediate',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$secondId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertSame(2, count($fake->sent));
    }
}
