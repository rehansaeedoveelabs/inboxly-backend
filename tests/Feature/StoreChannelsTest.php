<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class StoreChannelsTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_channels_persist_on_store_metadata(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $this->spa()->actingAs($admin)
            ->putJson("/api/v1/stores/{$store->id}/channels", [
                'email' => [
                    'from_name' => 'Acme',
                    'from_email' => 'hello@acme.test',
                    'reply_to' => 'support@acme.test',
                    'footer_address' => '1 Market St',
                ],
                'sms' => [
                    'sender_id' => 'ACME',
                    'compliance_text' => 'STOP to opt out',
                    'enabled' => true,
                ],
                'web_push' => [
                    'enabled' => false,
                    'prompt_delay_seconds' => 8,
                    'icon_label' => 'Acme',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.metadata.channels.email.from_email', 'hello@acme.test')
            ->assertJsonPath('data.metadata.channels.sms.enabled', true)
            ->assertJsonPath('data.metadata.channels.web_push.prompt_delay_seconds', 8);

        $this->assertSame('hello@acme.test', $store->fresh()->metadata['channels']['email']['from_email']);
    }
}
