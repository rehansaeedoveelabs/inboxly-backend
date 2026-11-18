<?php

namespace Tests\Feature;

use App\Actions\Automations\EnrollContactInAutomations;
use App\Actions\UpdateContactConsents;
use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStepRunStatus;
use App\Enums\AutomationStepType;
use App\Enums\AutomationTriggerType;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Events\ContactEmailSubscribed;
use App\Models\Automation;
use App\Models\AutomationEnrollment;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\FakeEmailSender;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

/**
 * After-commit semantics cannot be asserted under RefreshDatabase's wrapped test
 * transaction (callbacks never reach level 0). These tests migrate inboxly_testing
 * fresh without wrapping so outermost commits actually run.
 */
class AutomationContactSubscribedAfterCommitTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    public function test_event_implements_should_dispatch_after_commit(): void
    {
        $this->assertTrue(
            is_subclass_of(ContactEmailSubscribed::class, ShouldDispatchAfterCommit::class)
        );
    }

    public function test_successful_outer_transaction_produces_exactly_one_enrollment(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store);
        $contact = $this->contactIn($store, ['email' => 'outer-ok@example.com']);

        Event::fake([ContactEmailSubscribed::class]);

        DB::transaction(function () use ($contact) {
            app(UpdateContactConsents::class)->handle($contact, [
                ['channel' => 'email', 'status' => 'subscribed', 'source' => 'outer_ok'],
            ]);
            Event::assertNotDispatched(ContactEmailSubscribed::class);
        });

        Event::assertDispatchedTimes(ContactEmailSubscribed::class, 1);
        Event::assertDispatched(ContactEmailSubscribed::class, function (ContactEmailSubscribed $event) use ($contact, $store) {
            return $event->contactId === $contact->id && $event->storeId === $store->id;
        });

        // Event was faked — run enrollment as the real queued listener would after commit.
        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $this->assertSame(
            ConsentStatus::Subscribed,
            $contact->fresh()->consents()->where('channel', ConsentChannel::Email)->first()->status
        );
        $this->assertSame(
            1,
            AutomationEnrollment::query()
                ->where('automation_id', $automation->id)
                ->where('contact_id', $contact->id)
                ->count()
        );
    }

    public function test_outer_transaction_rollback_produces_no_enrollment_and_leaves_consent_unchanged(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->createActiveWelcome($admin, $store);
        $contact = $this->contactIn($store, ['email' => 'outer-rollback@example.com']);

        $consentEventsBefore = $contact->consentEvents()->count();

        Event::fake([ContactEmailSubscribed::class]);

        try {
            DB::transaction(function () use ($contact) {
                app(UpdateContactConsents::class)->handle($contact, [
                    ['channel' => 'email', 'status' => 'subscribed', 'source' => 'outer_rollback'],
                ]);

                throw new \RuntimeException('force outer rollback');
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('force outer rollback', $e->getMessage());
        }

        Event::assertNotDispatched(ContactEmailSubscribed::class);

        $contact->refresh();
        $this->assertSame(
            ConsentStatus::Nonsubscribed,
            $contact->consents()->where('channel', ConsentChannel::Email)->first()->status
        );
        $this->assertSame($consentEventsBefore, $contact->consentEvents()->count());
        $this->assertDatabaseMissing('automation_enrollments', ['contact_id' => $contact->id]);
        $this->assertCount(0, $fake->sent);
    }

    public function test_nested_transactions_defer_until_outermost_commit(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store);
        $contact = $this->contactIn($store, ['email' => 'nested-commit@example.com']);

        Event::fake([ContactEmailSubscribed::class]);

        DB::transaction(function () use ($contact, $fake) {
            DB::transaction(function () use ($contact) {
                app(UpdateContactConsents::class)->handle($contact, [
                    ['channel' => 'email', 'status' => 'subscribed', 'source' => 'nested'],
                ]);
            });

            Event::assertNotDispatched(ContactEmailSubscribed::class);
            $this->assertDatabaseMissing('automation_enrollments', ['contact_id' => $contact->id]);
            $this->assertCount(0, $fake->sent);
        });

        Event::assertDispatchedTimes(ContactEmailSubscribed::class, 1);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $this->assertSame(
            1,
            AutomationEnrollment::query()
                ->where('automation_id', $automation->id)
                ->where('contact_id', $contact->id)
                ->count()
        );
    }

    public function test_duplicate_event_delivery_still_one_enrollment(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store);
        $contact = $this->contactIn($store, ['email' => 'dup-after-commit@example.com']);

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [['channel' => 'email', 'status' => 'subscribed']],
        ])->assertOk();

        // Replay the domain event as duplicate delivery after commit.
        ContactEmailSubscribed::dispatch($contact->id, $store->id);
        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $this->assertSame(
            1,
            AutomationEnrollment::query()
                ->where('automation_id', $automation->id)
                ->where('contact_id', $contact->id)
                ->count()
        );
        $this->assertCount(1, $fake->sent);
    }

    public function test_genuine_subscribe_without_outer_transaction_enrolls_via_listener(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store);
        $contact = $this->contactIn($store, ['email' => 'direct-subscribe@example.com']);

        $this->spa()->actingAs($admin)->putJson("/api/v1/contacts/{$contact->id}/consents", [
            'consents' => [
                ['channel' => 'email', 'status' => 'subscribed', 'source' => 'signup'],
            ],
        ])->assertOk();

        $this->assertSame(
            1,
            AutomationEnrollment::query()
                ->where('automation_id', $automation->id)
                ->where('contact_id', $contact->id)
                ->count()
        );
        $this->assertCount(1, $fake->sent);
        $this->assertSame('direct-subscribe@example.com', $fake->sent[0]->to);
    }

    public function test_outer_commit_runs_real_listener_and_delivers_fake_email(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store);
        $emailStep = $automation->steps()->where('type', AutomationStepType::SendEmail)->firstOrFail();
        $contact = $this->contactIn($store, ['email' => 'outer-real-listener@example.com']);

        $this->assertSame(
            ConsentStatus::Nonsubscribed,
            $contact->consents()->where('channel', ConsentChannel::Email)->firstOrFail()->status
        );

        DB::transaction(function () use ($contact, $fake) {
            app(UpdateContactConsents::class)->handle($contact, [
                ['channel' => 'email', 'status' => 'subscribed', 'source' => 'outer_real_listener'],
            ]);

            $this->assertDatabaseMissing('automation_enrollments', [
                'contact_id' => $contact->id,
            ]);
            $this->assertCount(0, $fake->sent);
        });

        $enrollment = AutomationEnrollment::query()
            ->where('automation_id', $automation->id)
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertSame(1, AutomationEnrollment::query()
            ->where('automation_id', $automation->id)
            ->where('contact_id', $contact->id)
            ->count());
        $this->assertSame(AutomationEnrollmentStatus::Completed, $enrollment->status);

        $run = $enrollment->stepRuns()
            ->where('automation_step_id', $emailStep->id)
            ->first();
        $this->assertNotNull($run);
        $this->assertSame(AutomationStepRunStatus::Succeeded, $run->status);
        $this->assertNotEmpty($run->provider_message_id);

        $this->assertCount(1, $fake->sent);
        $this->assertSame('outer-real-listener@example.com', $fake->sent[0]->to);
        $this->assertSame('Welcome', $fake->sent[0]->subject);
        $this->assertSame(
            ConsentStatus::Subscribed,
            $contact->fresh()->consents()->where('channel', ConsentChannel::Email)->firstOrFail()->status
        );
    }

    private function createActiveWelcome($admin, $store): Automation
    {
        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'After-commit welcome',
            'steps' => [
                ['type' => 'send_email', 'config' => ['subject' => 'Welcome', 'body' => 'Thanks for joining.']],
            ],
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$id}/activate")->assertOk();

        return Automation::query()->findOrFail($id);
    }
}
