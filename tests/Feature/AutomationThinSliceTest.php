<?php

namespace Tests\Feature;

use App\Actions\AttachTags;
use App\Actions\Automations\EnrollContactInAutomations;
use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationTriggerType;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Events\ContactTagAdded;
use App\Models\AutomationEnrollment;
use App\Models\Tag;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\FakeEmailSender;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class AutomationThinSliceTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_contact_tag_added_is_after_commit(): void
    {
        $this->assertTrue(
            is_subclass_of(ContactTagAdded::class, ShouldDispatchAfterCommit::class)
        );
    }

    public function test_attaching_new_tag_enrolls_active_tag_added_automation(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $tag = Tag::factory()->create(['store_id' => $store->id, 'name' => 'VIP']);

        $automationId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'Tag welcome',
            'trigger_type' => AutomationTriggerType::ContactTagAdded->value,
            'steps' => [
                ['type' => 'send_email', 'config' => ['subject' => 'Tagged', 'body' => 'Hello VIP']],
            ],
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $contact = $this->contactIn($store, ['email' => 'tagged@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/contacts/{$contact->id}/tags", ['tag_ids' => [$tag->id]])
            ->assertOk();

        $this->assertSame(
            1,
            AutomationEnrollment::query()->where('automation_id', $automationId)->count()
        );
        $this->assertCount(1, $fake->sent);
    }

    public function test_reattach_existing_tag_does_not_dispatch_again(): void
    {
        Event::fake([ContactTagAdded::class]);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $tag = Tag::factory()->create(['store_id' => $store->id]);
        $contact = $this->contactIn($store);

        app(AttachTags::class)->handle($contact, [$tag->id]);
        Event::assertDispatchedTimes(ContactTagAdded::class, 1);

        app(AttachTags::class)->handle($contact, [$tag->id]);
        Event::assertDispatchedTimes(ContactTagAdded::class, 1);
    }

    public function test_condition_false_ends_without_send(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $automationId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'Conditional',
            'trigger_type' => AutomationTriggerType::ContactEmailSubscribed->value,
            'steps' => [
                [
                    'type' => 'condition',
                    'config' => [
                        'predicate' => 'email_subscribed',
                        'on_true_goto' => 'next',
                        'on_false_goto' => 'end',
                    ],
                ],
                ['type' => 'send_email', 'config' => ['subject' => 'Hi', 'body' => 'Body']],
            ],
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $contact = $this->contactIn($store, ['email' => 'unsub@example.com']);
        // Default consent is nonsubscribed — condition should fail and end.
        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(AutomationEnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertCount(0, $fake->sent);
    }

    public function test_condition_true_continues_to_send(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $automationId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'Conditional send',
            'steps' => [
                [
                    'type' => 'condition',
                    'config' => [
                        'predicate' => 'email_subscribed',
                        'on_true_goto' => 'next',
                        'on_false_goto' => 'end',
                    ],
                ],
                ['type' => 'send_email', 'config' => ['subject' => 'Hi', 'body' => 'Body']],
            ],
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $contact = $this->contactIn($store, ['email' => 'sub@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(AutomationEnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertCount(1, $fake->sent);
    }
}
