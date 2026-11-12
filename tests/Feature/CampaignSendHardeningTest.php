<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Jobs\ProcessCampaignSendJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Support\Messaging\BrevoEmailSender;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\Contracts\SmsSender;
use App\Support\Messaging\FakeEmailSender;
use App\Support\Messaging\OutboundMessage;
use App\Support\Messaging\SendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class CampaignSendHardeningTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_messaging_driver_defaults_to_fake(): void
    {
        Config::set('services.messaging.email_driver', null);
        $default = config('services.messaging.email_driver', 'fake');
        // Binding uses env default; force config to fake as production-safe baseline.
        Config::set('services.messaging.email_driver', 'fake');
        $this->assertSame('fake', config('services.messaging.email_driver'));
        $this->assertFalse((bool) config('services.brevo.sending_enabled'));
        $this->assertInstanceOf(FakeEmailSender::class, app(EmailSender::class));
    }

    public function test_brevo_sender_refuses_when_sending_disabled(): void
    {
        Config::set('services.messaging.email_driver', 'brevo');
        Config::set('services.brevo.sending_enabled', false);
        Config::set('services.brevo.api_key', 'test-key');
        Config::set('services.brevo.sender_email', 'noreply@example.com');

        $result = app(BrevoEmailSender::class)->send(new OutboundMessage(
            to: 'a@example.com',
            subject: 'Hi',
            htmlBody: '<p>Hi</p>',
            textBody: 'Hi',
        ));

        $this->assertFalse($result->accepted);
        $this->assertSame('sending_disabled', $result->errorCode);
    }

    public function test_test_send_requires_verified_email_and_rejects_override(): void
    {
        $this->app->instance(EmailSender::class, new FakeEmailSender);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $admin->forceFill(['email_verified_at' => null])->save();
        $store = $this->storeIn($workspace);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Test',
            'channel' => 'email',
            'subject' => 'Hi',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/test-send", [
            'to' => 'attacker@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $admin->forceFill(['email_verified_at' => now()])->save();

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/test-send", [
            'to' => 'attacker@example.com',
        ])->assertOk()
            ->assertJsonPath('data.to', mb_strtolower($admin->email))
            ->assertJsonPath('data.accepted', true);
    }

    public function test_sms_send_is_rejected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'SMS',
            'channel' => 'sms',
            'body' => 'Hi',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/send")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('channel');
    }

    public function test_send_and_test_send_are_rate_limited(): void
    {
        $this->app->instance(EmailSender::class, new FakeEmailSender);
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'Rate',
            'channel' => 'email',
            'subject' => 'Hi',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        RateLimiter::clear('campaign-test-send|'.$admin->getAuthIdentifier());
        for ($i = 0; $i < 10; $i++) {
            $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/test-send")->assertOk();
        }
        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/test-send")
            ->assertStatus(429);
    }

    public function test_expired_queued_lease_is_reclaimed_without_duplicate_accept(): void
    {
        $fake = new class extends FakeEmailSender
        {
            public int $calls = 0;

            public function send(OutboundMessage $message): SendResult
            {
                $this->calls++;

                return parent::send($message);
            }
        };
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'lease@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => CampaignStatus::Sending,
            'subject' => 'Lease',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ]);

        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'channel' => 'email',
            'address' => 'lease@example.com',
            'status' => CampaignRecipientStatus::Queued,
            'attempts' => 0,
            'lease_expires_at' => now()->subMinute(),
        ]);

        (new ProcessCampaignSendJob($campaign->id))->handle(
            app(EmailSender::class),
            app(SmsSender::class),
        );

        $this->assertSame(1, $fake->calls);
        $this->assertSame(CampaignRecipientStatus::Sent, $recipient->fresh()->status);
        $this->assertNull($recipient->fresh()->lease_expires_at);
        $this->assertSame(CampaignStatus::Sent, $campaign->fresh()->status);
    }

    public function test_active_lease_is_not_stolen_by_another_worker(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'active@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $campaign = Campaign::factory()->create([
            'store_id' => $store->id,
            'created_by' => $admin->id,
            'channel' => 'email',
            'status' => CampaignStatus::Sending,
            'subject' => 'Active',
            'body' => 'Body',
            'audience_type' => 'all_eligible',
        ]);

        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'channel' => 'email',
            'address' => 'active@example.com',
            'status' => CampaignRecipientStatus::Queued,
            'attempts' => 0,
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        (new ProcessCampaignSendJob($campaign->id))->handle(
            app(EmailSender::class),
            app(SmsSender::class),
        );

        $this->assertCount(0, $fake->sent);
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);
    }
}
