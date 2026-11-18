<?php

namespace Tests\Feature;

use App\Actions\Automations\EnrollContactInAutomations;
use App\Actions\UpdateContactConsents;
use App\Console\Commands\DispatchDueAutomationEnrollments;
use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStatus;
use App\Enums\AutomationStepRunStatus;
use App\Enums\AutomationStepType;
use App\Enums\AutomationTriggerType;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\SuppressionReason;
use App\Events\ContactEmailSubscribed;
use App\Jobs\ProcessAutomationEnrollmentJob;
use App\Models\Automation;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\Suppression;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\FakeEmailSender;
use App\Support\Messaging\OutboundMessage;
use App\Support\Messaging\SendResult;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class AutomationWelcomeE2ETest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_contact_email_subscribed_is_after_commit(): void
    {
        $this->assertTrue(
            is_subclass_of(ContactEmailSubscribed::class, ShouldDispatchAfterCommit::class)
        );
    }

    public function test_unchanged_consent_does_not_dispatch_trigger(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'same@example.com']);

        app(UpdateContactConsents::class)->handle($contact, [
            ['channel' => 'email', 'status' => 'subscribed'],
        ]);

        Event::fake([ContactEmailSubscribed::class]);

        app(UpdateContactConsents::class)->handle($contact->fresh(), [
            ['channel' => 'email', 'status' => 'subscribed'],
        ]);

        Event::assertNotDispatched(ContactEmailSubscribed::class);
        $this->assertSame(1, $contact->consentEvents()->where('channel', 'email')->where('status', 'subscribed')->count());
    }

    public function test_transaction_failure_does_not_dispatch_trigger(): void
    {
        Event::fake([ContactEmailSubscribed::class]);

        [, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store);

        try {
            app(UpdateContactConsents::class)->handle($contact, [
                ['channel' => 'email', 'status' => 'not-a-real-status'],
            ]);
            $this->fail('Expected ValueError');
        } catch (\ValueError) {
            // expected
        }

        Event::assertNotDispatched(ContactEmailSubscribed::class);
        $this->assertDatabaseMissing('automation_enrollments', ['contact_id' => $contact->id]);
    }

    public function test_duplicate_trigger_does_not_duplicate_enrollment(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store);
        $contact = $this->contactIn($store, ['email' => 'dup@example.com']);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );
        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $this->assertSame(1, AutomationEnrollment::query()->where('automation_id', $automation->id)->count());
    }

    public function test_draft_and_paused_do_not_enroll(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $draft = $this->createWelcomeDraft($admin, $store, 'Draft welcome');
        $paused = $this->createWelcomeDraft($admin, $store, 'Paused welcome');
        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$paused->id}/activate")->assertOk();
        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$paused->id}/pause")->assertOk();

        $contact = $this->contactIn($store, ['email' => 'skip@example.com']);
        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $this->assertDatabaseMissing('automation_enrollments', ['automation_id' => $draft->id]);
        $this->assertDatabaseMissing('automation_enrollments', ['automation_id' => $paused->id]);
    }

    public function test_store_isolation_blocks_foreign_automation_access(): void
    {
        [$adminA, $workspaceA] = $this->adminWithWorkspace();
        $storeA = $this->storeIn($workspaceA);
        $automation = $this->createWelcomeDraft($adminA, $storeA);

        [$adminB] = $this->adminWithWorkspace();

        $this->spa()->actingAs($adminB)->getJson("/api/v1/automations/{$automation->id}")
            ->assertForbidden();
    }

    public function test_delayed_execution_recovers_via_dispatch_due(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $automationId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'Delayed welcome',
            'steps' => [
                ['type' => 'delay', 'config' => ['delay_minutes' => 60]],
                ['type' => 'send_email', 'config' => ['subject' => 'Hi', 'body' => 'Welcome']],
            ],
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $contact = $this->contactIn($store, ['email' => 'delay@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(1, $enrollment->fresh()->current_step_position);
        $this->assertTrue($enrollment->fresh()->next_run_at->isFuture());
        $this->assertCount(0, $fake->sent);

        // Simulate worker interruption recovery: due time reached.
        $enrollment->forceFill(['next_run_at' => now('UTC')->subMinute(), 'lease_expires_at' => null])->save();

        Artisan::call(DispatchDueAutomationEnrollments::class, ['--limit' => 10]);

        $this->assertSame(AutomationEnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertCount(1, $fake->sent);
        $this->assertSame('delay@example.com', $fake->sent[0]->to);
    }

    public function test_pause_freezes_and_resume_continues(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $automationId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'Pause welcome',
            'steps' => [
                ['type' => 'delay', 'config' => ['delay_minutes' => 30]],
                ['type' => 'send_email', 'config' => ['subject' => 'Hi', 'body' => 'Welcome']],
            ],
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $contact = $this->contactIn($store, ['email' => 'pause@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $enrollment->forceFill([
            'next_run_at' => now('UTC')->subMinute(),
            'lease_expires_at' => null,
        ])->save();

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/pause")->assertOk();

        (new ProcessAutomationEnrollmentJob($enrollment->id))->handle(app(\App\Actions\Automations\SendAutomationEmail::class));

        $this->assertSame(AutomationEnrollmentStatus::Active, $enrollment->fresh()->status);
        $this->assertCount(0, $fake->sent);

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $this->assertSame(AutomationEnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertCount(1, $fake->sent);
    }

    public function test_archive_cancels_pending_enrollment(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $automationId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'Archive welcome',
            'steps' => [
                ['type' => 'delay', 'config' => ['delay_minutes' => 120]],
                ['type' => 'send_email', 'config' => ['subject' => 'Hi', 'body' => 'Welcome']],
            ],
        ])->assertCreated()->json('data.id');

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $contact = $this->contactIn($store, ['email' => 'archive@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(AutomationEnrollmentStatus::Active, $enrollment->status);

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/archive")->assertOk();

        $enrollment->refresh();
        $this->assertSame(AutomationEnrollmentStatus::Cancelled, $enrollment->status);
        $this->assertSame('automation_archived', $enrollment->cancel_reason);
    }

    public function test_unsubscribed_suppressed_and_deleted_contacts_are_not_sent(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);

        $automationId = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => 'Gate welcome',
            'steps' => [
                ['type' => 'delay', 'config' => ['delay_minutes' => 10]],
                ['type' => 'send_email', 'config' => ['subject' => 'Hi', 'body' => 'Welcome']],
            ],
        ])->assertCreated()->json('data.id');
        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automationId}/activate")->assertOk();

        $cUnsub = $this->contactIn($store, ['email' => 'later-unsub@example.com']);
        $cUnsub->consents()->where('channel', ConsentChannel::Email)->update(['status' => ConsentStatus::Subscribed]);
        $cBlocked = $this->contactIn($store, ['email' => 'later-blocked@example.com']);
        $cBlocked->consents()->where('channel', ConsentChannel::Email)->update(['status' => ConsentStatus::Subscribed]);
        Suppression::query()->create([
            'store_id' => $store->id,
            'channel' => 'email',
            'address' => 'later-blocked@example.com',
            'reason' => SuppressionReason::Complaint,
            'source' => 'test',
        ]);
        $cDeleted = $this->contactIn($store, ['email' => 'later-gone@example.com']);
        $cDeleted->consents()->where('channel', ConsentChannel::Email)->update(['status' => ConsentStatus::Subscribed]);

        foreach ([$cUnsub, $cBlocked, $cDeleted] as $contact) {
            app(EnrollContactInAutomations::class)->handle(
                $contact->id,
                $store->id,
                AutomationTriggerType::ContactEmailSubscribed,
            );
        }

        $before = count($fake->sent);

        $cUnsub->consents()->where('channel', ConsentChannel::Email)->update(['status' => ConsentStatus::Unsubscribed]);
        $cDeleted->delete();

        foreach ([$cUnsub, $cBlocked, $cDeleted] as $contact) {
            $enrollment = AutomationEnrollment::query()
                ->where('automation_id', $automationId)
                ->where('contact_id', $contact->id)
                ->firstOrFail();
            $enrollment->forceFill(['next_run_at' => now('UTC')->subMinute(), 'lease_expires_at' => null])->save();
            (new ProcessAutomationEnrollmentJob($enrollment->id))->handle(app(\App\Actions\Automations\SendAutomationEmail::class));
        }

        $this->assertSame($before, count($fake->sent));

        foreach ([$cUnsub, $cBlocked, $cDeleted] as $contact) {
            $enrollment = AutomationEnrollment::query()
                ->where('automation_id', $automationId)
                ->where('contact_id', $contact->id)
                ->firstOrFail();
            $run = $enrollment->stepRuns()->whereHas('step', fn ($q) => $q->where('type', AutomationStepType::SendEmail))->first();
            $this->assertNotNull($run);
            $this->assertSame(AutomationStepRunStatus::Skipped, $run->status);
            $this->assertSame(AutomationEnrollmentStatus::Completed, $enrollment->fresh()->status);
        }
    }

    public function test_successful_fake_delivery_and_history(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store, 'Welcome', 'Hello friend');

        $contact = $this->contactIn($store, ['email' => 'ok@example.com', 'first_name' => 'Ada']);

        app(UpdateContactConsents::class)->handle($contact, [
            ['channel' => 'email', 'status' => 'subscribed'],
        ]);

        // RefreshDatabase wraps tests in a transaction, so after-commit listeners do not
        // run here. Enrollment is exercised directly; after-commit coupling is covered by
        // AutomationContactSubscribedAfterCommitTest.
        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame(AutomationEnrollmentStatus::Completed, $enrollment->status);
        $this->assertCount(1, $fake->sent);
        $this->assertSame('ok@example.com', $fake->sent[0]->to);
        $this->assertSame('Welcome', $fake->sent[0]->subject);
        $this->assertSame(
            AutomationStepRun::idempotencyKeyFor($enrollment->id, $automation->steps()->where('type', 'send_email')->first()->id),
            $fake->sent[0]->idempotencyKey,
        );

        $history = $this->spa()->actingAs($admin)->getJson("/api/v1/automations/{$automation->id}/enrollments")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $history);
        $this->assertSame('completed', $history[0]['status']);
        $this->assertSame('succeeded', $history[0]['step_runs'][0]['status']);
        $this->assertNotEmpty($history[0]['step_runs'][0]['provider_message_id']);
    }

    public function test_failed_delivery_records_and_retry_is_safe(): void
    {
        $failing = new class implements EmailSender
        {
            public int $calls = 0;

            public function send(OutboundMessage $message): SendResult
            {
                $this->calls++;

                return SendResult::fail('provider_error', 'boom', retryable: true);
            }
        };
        $this->app->instance(EmailSender::class, $failing);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $automation = $this->createActiveWelcome($admin, $store);

        $contact = $this->contactIn($store, ['email' => 'fail@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        config(['services.messaging.max_attempts' => 2]);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $run = $enrollment->stepRuns()->first();

        // First attempt failed retryable → pending again with future next_run.
        $this->assertContains($run->fresh()->status, [
            AutomationStepRunStatus::Pending,
            AutomationStepRunStatus::Failed,
        ]);

        // Force retries until terminal.
        for ($i = 0; $i < 3; $i++) {
            $enrollment->forceFill(['next_run_at' => now('UTC')->subMinute(), 'lease_expires_at' => null])->save();
            $run->forceFill([
                'status' => AutomationStepRunStatus::Pending,
                'lease_expires_at' => null,
            ])->save();
            (new ProcessAutomationEnrollmentJob($enrollment->id))->handle(app(\App\Actions\Automations\SendAutomationEmail::class));
            if ($enrollment->fresh()->status === AutomationEnrollmentStatus::Failed) {
                break;
            }
        }

        $this->assertSame(AutomationEnrollmentStatus::Failed, $enrollment->fresh()->status);
        $this->assertSame(AutomationStepRunStatus::Failed, $run->fresh()->status);
        $this->assertGreaterThanOrEqual(1, $failing->calls);
    }

    public function test_duplicate_job_cannot_deliver_twice(): void
    {
        $fake = new FakeEmailSender;
        $this->app->instance(EmailSender::class, $fake);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $this->createActiveWelcome($admin, $store);

        $contact = $this->contactIn($store, ['email' => 'once@example.com']);
        $contact->consents()->where('channel', ConsentChannel::Email)->update([
            'status' => ConsentStatus::Subscribed,
        ]);

        app(EnrollContactInAutomations::class)->handle(
            $contact->id,
            $store->id,
            AutomationTriggerType::ContactEmailSubscribed,
        );

        $enrollment = AutomationEnrollment::query()->where('contact_id', $contact->id)->firstOrFail();
        $this->assertCount(1, $fake->sent);

        // Re-run job after completion — must not send again.
        $enrollment->forceFill([
            'status' => AutomationEnrollmentStatus::Active,
            'current_step_position' => 0,
            'next_run_at' => now('UTC')->subMinute(),
            'lease_expires_at' => null,
            'completed_at' => null,
        ])->save();

        $run = $enrollment->stepRuns()->first();
        // Keep succeeded status — claim should no-op / advance without send.
        $this->assertSame(AutomationStepRunStatus::Succeeded, $run->status);

        (new ProcessAutomationEnrollmentJob($enrollment->id))->handle(app(\App\Actions\Automations\SendAutomationEmail::class));

        $this->assertCount(1, $fake->sent);
    }

    private function createWelcomeDraft($admin, $store, string $name = 'Welcome draft'): Automation
    {
        $id = $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/automations", [
            'name' => $name,
            'steps' => [
                ['type' => 'send_email', 'config' => ['subject' => 'Welcome', 'body' => 'Thanks for joining.']],
            ],
        ])->assertCreated()->json('data.id');

        return Automation::query()->findOrFail($id);
    }

    private function createActiveWelcome($admin, $store, string $subject = 'Welcome', string $body = 'Thanks for joining.'): Automation
    {
        $automation = $this->createWelcomeDraft($admin, $store, 'Active '.$subject);
        if ($subject !== 'Welcome' || $body !== 'Thanks for joining.') {
            $this->spa()->actingAs($admin)->putJson("/api/v1/automations/{$automation->id}", [
                'steps' => [
                    ['type' => 'send_email', 'config' => ['subject' => $subject, 'body' => $body]],
                ],
            ])->assertOk();
        }

        $this->spa()->actingAs($admin)->postJson("/api/v1/automations/{$automation->id}/activate")->assertOk();

        return $automation->fresh()->load('steps');
    }
}
