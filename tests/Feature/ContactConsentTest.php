<?php

namespace Tests\Feature;

use App\Models\ConsentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ContactConsentTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_consent_update_sets_current_status_and_appends_events(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $contact = $this->contactIn($this->storeIn($workspace));

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [
                ['channel' => 'email', 'status' => 'subscribed', 'source' => 'signup_form'],
                ['channel' => 'sms', 'status' => 'unsubscribed'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.consents.email.status', 'subscribed')
            ->assertJsonPath('data.consents.email.source', 'signup_form')
            ->assertJsonPath('data.consents.sms.status', 'unsubscribed')
            ->assertJsonPath('data.consents.push.status', 'nonsubscribed');

        $this->assertDatabaseHas('contact_consents', [
            'contact_id' => $contact->id, 'channel' => 'email', 'status' => 'subscribed',
        ]);
        $this->assertNotNull($contact->consents()->where('channel', 'email')->first()->consented_at);
        $this->assertSame(2, $contact->consentEvents()->count());

        // Re-sending the same status is a no-op; a real change appends another event.
        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [['channel' => 'email', 'status' => 'subscribed']],
        ])->assertOk();
        $this->assertSame(2, $contact->consentEvents()->count());

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [['channel' => 'email', 'status' => 'unsubscribed', 'source' => 'link']],
        ])->assertOk();

        $this->assertSame(3, $contact->consentEvents()->count());
        $latest = $contact->consentEvents()->where('channel', 'email')->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame('unsubscribed', $latest->status->value);
        $this->assertSame('link', $latest->source);
        $this->assertNull($contact->consents()->where('channel', 'email')->first()->consented_at);
    }

    public function test_consent_validation(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $contact = $this->contactIn($this->storeIn($workspace));

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [['channel' => 'fax', 'status' => 'maybe']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['consents.0.channel', 'consents.0.status']);

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [])
            ->assertUnprocessable();
    }

    public function test_consent_events_are_append_only(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $contact = $this->contactIn($this->storeIn($workspace));
        $event = ConsentEvent::factory()->create(['contact_id' => $contact->id]);

        $this->expectException(LogicException::class);
        $event->update(['status' => 'unsubscribed']);
    }
}
