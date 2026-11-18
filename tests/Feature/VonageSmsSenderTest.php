<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Support\Messaging\Contracts\SmsSender;
use App\Support\Messaging\FakeSmsSender;
use App\Support\Messaging\VonageSmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class VonageSmsSenderTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_vonage_sender_refuses_when_sending_disabled(): void
    {
        Config::set('services.messaging.sms_driver', 'vonage');
        Config::set('services.vonage.sending_enabled', false);
        Config::set('services.vonage.api_key', 'key');
        Config::set('services.vonage.api_secret', 'secret');
        Config::set('services.vonage.from', 'Inboxly');

        $result = app(VonageSmsSender::class)->send([
            'to' => '+15551234567',
            'body' => 'Hello',
        ]);

        $this->assertFalse($result->accepted);
        $this->assertSame('sending_disabled', $result->errorCode);
    }

    public function test_vonage_sender_requires_credentials_and_from(): void
    {
        Config::set('services.vonage.sending_enabled', true);
        Config::set('services.vonage.api_key', '');
        Config::set('services.vonage.api_secret', 'secret');
        Config::set('services.vonage.from', 'Inboxly');

        $missingKey = app(VonageSmsSender::class)->send([
            'to' => '+15551234567',
            'body' => 'Hello',
        ]);
        $this->assertSame('missing_api_key', $missingKey->errorCode);

        Config::set('services.vonage.api_key', 'key');
        Config::set('services.vonage.api_secret', '');
        $missingSecret = app(VonageSmsSender::class)->send([
            'to' => '+15551234567',
            'body' => 'Hello',
        ]);
        $this->assertSame('missing_api_secret', $missingSecret->errorCode);

        Config::set('services.vonage.api_secret', 'secret');
        Config::set('services.vonage.from', '');
        $missingFrom = app(VonageSmsSender::class)->send([
            'to' => '+15551234567',
            'body' => 'Hello',
        ]);
        $this->assertSame('missing_from', $missingFrom->errorCode);
    }

    public function test_vonage_sender_posts_sms_and_returns_message_id(): void
    {
        Config::set('services.vonage.sending_enabled', true);
        Config::set('services.vonage.api_key', 'key');
        Config::set('services.vonage.api_secret', 'secret');
        Config::set('services.vonage.from', 'Inboxly');
        Config::set('services.vonage.base_url', 'https://rest.nexmo.com');

        Http::fake([
            'https://rest.nexmo.com/sms/json' => Http::response([
                'message-count' => '1',
                'messages' => [[
                    'to' => '15551234567',
                    'message-id' => 'msg-123',
                    'status' => '0',
                ]],
            ], 200),
        ]);

        $result = app(VonageSmsSender::class)->send([
            'to' => '+1 (555) 123-4567',
            'body' => 'Hello from Inboxly',
            'idempotency_key' => 'campaign:recipient:1',
        ]);

        $this->assertTrue($result->accepted);
        $this->assertSame('msg-123', $result->providerMessageId);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://rest.nexmo.com/sms/json'
                && ($data['to'] ?? null) === '15551234567'
                && ($data['from'] ?? null) === 'Inboxly'
                && ($data['text'] ?? null) === 'Hello from Inboxly'
                && ($data['api_key'] ?? null) === 'key'
                && ($data['client-ref'] ?? null) === 'campaign:recipient:1';
        });
    }

    public function test_vonage_sender_maps_provider_status_errors(): void
    {
        Config::set('services.vonage.sending_enabled', true);
        Config::set('services.vonage.api_key', 'key');
        Config::set('services.vonage.api_secret', 'secret');
        Config::set('services.vonage.from', 'Inboxly');
        Config::set('services.vonage.base_url', 'https://rest.nexmo.com');

        Http::fake([
            'https://rest.nexmo.com/sms/json' => Http::response([
                'message-count' => '1',
                'messages' => [[
                    'status' => '2',
                    'error-text' => 'Missing username',
                ]],
            ], 200),
        ]);

        $result = app(VonageSmsSender::class)->send([
            'to' => '+15551234567',
            'body' => 'Hello',
        ]);

        $this->assertFalse($result->accepted);
        $this->assertSame('vonage_status_2', $result->errorCode);
        $this->assertStringContainsString('Missing username', (string) $result->errorMessage);
    }

    public function test_sms_driver_binding_resolves_vonage(): void
    {
        Config::set('services.messaging.sms_driver', 'vonage');
        $this->app->forgetInstance(SmsSender::class);

        $this->assertInstanceOf(VonageSmsSender::class, app(SmsSender::class));
    }

    public function test_sms_campaign_send_with_vonage_driver_succeeds_when_http_faked(): void
    {
        Config::set('services.messaging.sms_driver', 'vonage');
        Config::set('services.vonage.sending_enabled', true);
        Config::set('services.vonage.api_key', 'key');
        Config::set('services.vonage.api_secret', 'secret');
        Config::set('services.vonage.from', 'Inboxly');
        Config::set('services.vonage.base_url', 'https://rest.nexmo.com');
        $this->app->forgetInstance(SmsSender::class);

        Http::fake([
            'https://rest.nexmo.com/sms/json' => Http::response([
                'message-count' => '1',
                'messages' => [[
                    'to' => '15551234567',
                    'message-id' => 'msg-campaign',
                    'status' => '0',
                ]],
            ], 200),
        ]);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['phone' => '+15551234567']);
        $contact->consents()->where('channel', ConsentChannel::Sms)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $campaignId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/campaigns", [
            'name' => 'SMS vonage',
            'channel' => 'sms',
            'body' => 'Hello Vonage',
            'audience_type' => 'all_eligible',
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/campaigns/{$campaignId}/send")
            ->assertOk()
            ->assertJsonPath('data.status', CampaignStatus::Sent->value);

        $this->assertDatabaseHas('campaign_recipients', [
            'campaign_id' => $campaignId,
            'provider' => 'vonage',
            'provider_message_id' => 'msg-campaign',
            'status' => 'sent',
        ]);
    }

    public function test_fake_sms_driver_still_binds(): void
    {
        Config::set('services.messaging.sms_driver', 'fake');
        $this->app->forgetInstance(SmsSender::class);

        $this->assertInstanceOf(FakeSmsSender::class, app(SmsSender::class));
    }
}
