<?php

namespace Tests\Feature;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ContactRestoreTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_create_against_soft_deleted_contact_returns_409_without_restore(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'gone@example.com']);
        $contact->consents()->where('channel', 'email')->update(['status' => ConsentStatus::Subscribed->value]);
        $contact->delete();

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contacts", [
            'email' => 'gone@example.com',
            'first_name' => 'Retry',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'contact_deleted')
            ->assertJsonPath('contact.id', $contact->id)
            ->assertJsonPath('contact.consents.email.status', 'subscribed');

        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
        $this->assertSame(0, $contact->fresh()->consentEvents()->count());
    }

    public function test_create_with_restore_true_restores_preserving_consents_and_sets_review_gate(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'gone@example.com', 'first_name' => 'Old']);
        $contact->consents()->where('channel', 'email')->update(['status' => ConsentStatus::Subscribed->value]);
        $contact->consents()->where('channel', 'sms')->update(['status' => ConsentStatus::Unsubscribed->value]);
        $eventCount = $contact->consentEvents()->count();
        $contact->delete();

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contacts", [
            'email' => 'gone@example.com',
            'first_name' => 'Restored',
            'restore' => true,
        ])->assertCreated()
            ->assertJsonPath('data.id', $contact->id)
            ->assertJsonPath('data.first_name', 'Restored')
            ->assertJsonPath('data.consent_review_required', true)
            ->assertJsonPath('data.consents.email.status', 'subscribed')
            ->assertJsonPath('data.consents.sms.status', 'unsubscribed')
            ->assertJsonPath('data.marketing_eligible.email', false)
            ->assertJsonPath('data.marketing_eligible.sms', false);

        $fresh = $contact->fresh();
        $this->assertNull($fresh->deleted_at);
        $this->assertNotNull($fresh->consent_review_required_at);
        $this->assertSame($eventCount, $fresh->consentEvents()->count());
        $this->assertFalse($fresh->load('consents')->isMarketingEligible(ConsentChannel::Email));
    }

    public function test_restore_endpoint_sets_review_gate_and_preserves_consents(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'restore-me@example.com']);
        $contact->consents()->where('channel', 'email')->update(['status' => ConsentStatus::Subscribed->value]);
        $contact->delete();

        $this->spa()->actingAs($admin)->postJson("/api/v1/contacts/{$contact->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $contact->id)
            ->assertJsonPath('data.consent_review_required', true)
            ->assertJsonPath('data.consents.email.status', 'subscribed')
            ->assertJsonPath('data.marketing_eligible.email', false);

        $this->assertNull($contact->fresh()->deleted_at);
        $this->assertSame(0, $contact->fresh()->consentEvents()->count());
    }

    public function test_consent_review_clears_gate_without_changing_statuses_or_events(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'review@example.com']);
        $contact->consents()->where('channel', 'email')->update(['status' => ConsentStatus::Subscribed->value]);
        $contact->consents()->where('channel', 'sms')->update(['status' => ConsentStatus::Unsubscribed->value]);
        $contact->forceFill(['consent_review_required_at' => now()])->save();

        $this->spa()->actingAs($admin)->postJson("/api/v1/contacts/{$contact->id}/consent-review")
            ->assertOk()
            ->assertJsonPath('data.consent_review_required', false)
            ->assertJsonPath('data.consents.email.status', 'subscribed')
            ->assertJsonPath('data.consents.sms.status', 'unsubscribed')
            ->assertJsonPath('data.marketing_eligible.email', true)
            ->assertJsonPath('data.marketing_eligible.sms', false);

        $fresh = $contact->fresh()->load('consents');
        $this->assertNull($fresh->consent_review_required_at);
        $this->assertSame(0, $fresh->consentEvents()->count());
        $this->assertTrue($fresh->isMarketingEligible(ConsentChannel::Email));
        $this->assertFalse($fresh->isMarketingEligible(ConsentChannel::Sms));
    }

    public function test_consent_put_does_not_clear_review_gate(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store);
        $contact->forceFill(['consent_review_required_at' => now()])->save();

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [['channel' => 'email', 'status' => 'subscribed', 'source' => 'manual']],
        ])->assertOk()
            ->assertJsonPath('data.consents.email.status', 'subscribed')
            ->assertJsonPath('data.consent_review_required', true)
            ->assertJsonPath('data.marketing_eligible.email', false);

        $this->assertNotNull($contact->fresh()->consent_review_required_at);
    }

    public function test_update_cannot_steal_soft_deleted_contact_email(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $gone = $this->contactIn($store, ['email' => 'taken@example.com']);
        $gone->delete();
        $active = $this->contactIn($store, ['email' => 'active@example.com']);

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$active->id}", [
            'email' => 'taken@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_restore_and_review_are_denied_to_outsiders(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'private@example.com']);
        $contact->forceFill(['consent_review_required_at' => now()])->save();
        $contact->delete();
        $outsider = User::factory()->create();

        $this->spa()->actingAs($outsider)->postJson("/api/v1/contacts/{$contact->id}/restore")
            ->assertForbidden();

        $contact->restore();
        $contact->forceFill(['consent_review_required_at' => now()])->save();

        $this->spa()->actingAs($outsider)->postJson("/api/v1/contacts/{$contact->id}/consent-review")
            ->assertForbidden();
    }

    public function test_active_duplicate_create_still_returns_422(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->contactIn($store, ['email' => 'live@example.com']);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/contacts", [
            'email' => 'live@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
}
