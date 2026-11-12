<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\SuppressionReason;
use App\Models\BrevoWebhookEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class BrevoWebhookTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.brevo.webhook_secret', 'test-brevo-webhook-token');
    }

    public function test_rejects_missing_or_invalid_bearer_token(): void
    {
        $this->postJson('/api/v1/brevo/webhooks', [
            'event' => 'delivered',
            'email' => 'a@example.com',
        ])->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer wrong')
            ->postJson('/api/v1/brevo/webhooks', [
                'event' => 'delivered',
                'email' => 'a@example.com',
            ])->assertUnauthorized();
    }

    public function test_rejects_when_webhook_secret_unconfigured(): void
    {
        Config::set('services.brevo.webhook_secret', '');

        $this->withHeader('Authorization', 'Bearer test-brevo-webhook-token')
            ->postJson('/api/v1/brevo/webhooks', [
                'event' => 'delivered',
                'email' => 'a@example.com',
            ])->assertUnauthorized();
    }

    public function test_hard_bounce_is_idempotent_and_suppresses(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'bounce@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => CampaignStatus::Sent,
            'subject' => 'Hi',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ]);

        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'channel' => 'email',
            'address' => 'bounce@example.com',
            'status' => CampaignRecipientStatus::Sent,
            'provider_message_id' => '201798300811.5787683@relay.domain.com',
            'sent_at' => now(),
        ]);

        $payload = [
            'event' => 'hard_bounce',
            'email' => 'bounce@example.com',
            'id' => 12345,
            'ts_event' => 1604933654,
            'message-id' => '<201798300811.5787683@relay.domain.com>',
            'reason' => 'mailbox unavailable',
        ];

        $this->withHeader('Authorization', 'Bearer test-brevo-webhook-token')
            ->postJson('/api/v1/brevo/webhooks', $payload)
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $this->assertSame(CampaignRecipientStatus::Bounced, $recipient->fresh()->status);
        $this->assertDatabaseHas('suppressions', [
            'store_id' => $store->id,
            'address' => 'bounce@example.com',
            'reason' => SuppressionReason::HardBounce->value,
        ]);

        $this->withHeader('Authorization', 'Bearer test-brevo-webhook-token')
            ->postJson('/api/v1/brevo/webhooks', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicates', 1);

        $this->assertSame(1, BrevoWebhookEvent::query()->count());
    }

    public function test_spam_and_unsubscribe_update_consent_and_suppression(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'leave@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => CampaignStatus::Sent,
            'subject' => 'Hi',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ]);

        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'channel' => 'email',
            'address' => 'leave@example.com',
            'status' => CampaignRecipientStatus::Sent,
            'provider_message_id' => 'msg-unsub-1',
            'sent_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer test-brevo-webhook-token')
            ->postJson('/api/v1/brevo/webhooks', [
                'event' => 'spam',
                'email' => 'leave@example.com',
                'id' => 1,
                'ts_event' => 1,
                'message-id' => 'msg-unsub-1',
            ])->assertOk();

        $this->assertDatabaseHas('suppressions', [
            'store_id' => $store->id,
            'address' => 'leave@example.com',
            'reason' => SuppressionReason::Complaint->value,
        ]);

        $this->withHeader('Authorization', 'Bearer test-brevo-webhook-token')
            ->postJson('/api/v1/brevo/webhooks', [
                'event' => 'unsubscribed',
                'email' => 'leave@example.com',
                'id' => 2,
                'ts_event' => 2,
                'message-id' => 'msg-unsub-1',
            ])->assertOk();

        $this->assertSame(CampaignRecipientStatus::Unsubscribed, $recipient->fresh()->status);
        $this->assertSame(
            ConsentStatus::Unsubscribed,
            $contact->fresh()->consents()->where('channel', ConsentChannel::Email)->first()->status,
        );
        $this->assertDatabaseHas('consent_events', [
            'contact_id' => $contact->id,
            'channel' => ConsentChannel::Email->value,
            'status' => ConsentStatus::Unsubscribed->value,
            'source' => 'brevo_webhook',
        ]);
    }

    public function test_ignores_invalid_payload_shapes(): void
    {
        $this->withHeader('Authorization', 'Bearer test-brevo-webhook-token')
            ->postJson('/api/v1/brevo/webhooks', [
                'not_an_event' => true,
            ])->assertOk()
            ->assertJsonPath('data.ignored', 1);
    }
}
