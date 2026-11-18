<?php

namespace App\Jobs;

use App\Actions\Automations\SendAutomationEmail;
use App\Enums\AutomationEnrollmentStatus;
use App\Enums\AutomationStatus;
use App\Enums\AutomationStepRunStatus;
use App\Enums\AutomationStepType;
use App\Enums\ConsentChannel;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStep;
use App\Models\AutomationStepRun;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessAutomationEnrollmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $enrollmentId) {}

    public function handle(SendAutomationEmail $sendEmail): void
    {
        $enrollment = AutomationEnrollment::query()->find($this->enrollmentId);
        if ($enrollment === null) {
            return;
        }

        if ($enrollment->status !== AutomationEnrollmentStatus::Active) {
            return;
        }

        $automation = $enrollment->automation;
        if ($automation === null) {
            return;
        }

        if ($automation->status === AutomationStatus::Paused) {
            // Freeze: leave next_run_at as-is; do not advance.
            $this->releaseLease($enrollment);

            return;
        }

        if ($automation->status === AutomationStatus::Archived) {
            $this->cancelEnrollment($enrollment, 'automation_archived');

            return;
        }

        if ($automation->status !== AutomationStatus::Active) {
            $this->releaseLease($enrollment);

            return;
        }

        if ($enrollment->next_run_at !== null && $enrollment->next_run_at->isFuture()) {
            return;
        }

        if (! $this->claimEnrollment($enrollment)) {
            return;
        }

        $enrollment->refresh();
        $step = AutomationStep::query()
            ->where('automation_id', $automation->getKey())
            ->where('position', $enrollment->current_step_position)
            ->first();

        if ($step === null) {
            $this->completeEnrollment($enrollment);

            return;
        }

        $run = $this->resolveStepRun($enrollment, $step);

        if ($run->status === AutomationStepRunStatus::Succeeded
            || $run->status === AutomationStepRunStatus::Skipped) {
            $this->advancePastStep($enrollment, $step);

            return;
        }

        if ($run->status === AutomationStepRunStatus::Cancelled) {
            return;
        }

        if (! $this->claimStepRun($run)) {
            return;
        }

        $run->refresh();

        if ($step->type === AutomationStepType::Delay) {
            $this->executeDelay($enrollment, $step, $run);

            return;
        }

        if ($step->type === AutomationStepType::SendEmail) {
            $this->executeSendEmail($enrollment, $step, $run, $sendEmail);

            return;
        }

        if ($step->type === AutomationStepType::Condition) {
            $this->executeCondition($enrollment, $step, $run);

            return;
        }

        $this->failStepRun($run, 'unknown_step', 'Unsupported automation step type.');
        $this->failEnrollment($enrollment, 'unknown_step');
    }

    private function claimEnrollment(AutomationEnrollment $enrollment): bool
    {
        $leaseSeconds = max(30, (int) config('services.messaging.recipient_lease_seconds', 120));
        $leaseExpires = CarbonImmutable::now('UTC')->addSeconds($leaseSeconds);

        $claimed = DB::table('automation_enrollments')
            ->where('id', $enrollment->getKey())
            ->where('status', AutomationEnrollmentStatus::Active->value)
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->update([
                'lease_expires_at' => $leaseExpires,
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    private function releaseLease(AutomationEnrollment $enrollment): void
    {
        DB::table('automation_enrollments')
            ->where('id', $enrollment->getKey())
            ->update([
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function resolveStepRun(AutomationEnrollment $enrollment, AutomationStep $step): AutomationStepRun
    {
        $key = AutomationStepRun::idempotencyKeyFor($enrollment->getKey(), $step->getKey());

        return AutomationStepRun::query()->firstOrCreate(
            [
                'enrollment_id' => $enrollment->getKey(),
                'automation_step_id' => $step->getKey(),
            ],
            [
                'attempt' => 0,
                'status' => AutomationStepRunStatus::Pending,
                'idempotency_key' => $key,
            ],
        );
    }

    private function claimStepRun(AutomationStepRun $run): bool
    {
        if ($run->status === AutomationStepRunStatus::Succeeded
            || $run->status === AutomationStepRunStatus::Skipped
            || $run->status === AutomationStepRunStatus::Cancelled) {
            return false;
        }

        $leaseSeconds = max(30, (int) config('services.messaging.recipient_lease_seconds', 120));
        $leaseExpires = CarbonImmutable::now('UTC')->addSeconds($leaseSeconds);

        $claimed = DB::table('automation_step_runs')
            ->where('id', $run->getKey())
            ->whereIn('status', [
                AutomationStepRunStatus::Pending->value,
                AutomationStepRunStatus::Failed->value,
                AutomationStepRunStatus::Running->value,
            ])
            ->where(function ($query) {
                $query->where('status', '!=', AutomationStepRunStatus::Running->value)
                    ->orWhereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->update([
                'status' => AutomationStepRunStatus::Running->value,
                'attempt' => DB::raw('attempt + 1'),
                'started_at' => now(),
                'lease_expires_at' => $leaseExpires,
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    private function executeDelay(
        AutomationEnrollment $enrollment,
        AutomationStep $step,
        AutomationStepRun $run,
    ): void {
        $minutes = $step->delayMinutes();
        $nextRun = CarbonImmutable::now('UTC')->addMinutes($minutes);

        DB::table('automation_step_runs')
            ->where('id', $run->getKey())
            ->update([
                'status' => AutomationStepRunStatus::Succeeded->value,
                'finished_at' => now(),
                'lease_expires_at' => null,
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        DB::table('automation_enrollments')
            ->where('id', $enrollment->getKey())
            ->where('status', AutomationEnrollmentStatus::Active->value)
            ->update([
                'current_step_position' => $enrollment->current_step_position + 1,
                'next_run_at' => $nextRun,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        if ($minutes <= 0) {
            self::dispatch($enrollment->getKey());
        }
        // Delayed work is recovered by automations:dispatch-due.
    }

    private function executeSendEmail(
        AutomationEnrollment $enrollment,
        AutomationStep $step,
        AutomationStepRun $run,
        SendAutomationEmail $sendEmail,
    ): void {
        $contact = Contact::query()->withTrashed()->find($enrollment->contact_id);
        if ($contact === null) {
            $this->failStepRun($run, 'contact_missing', 'Contact not found.');
            $this->failEnrollment($enrollment, 'contact_missing');

            return;
        }

        // Re-check succeeded under race (duplicate job).
        $run->refresh();
        if ($run->status === AutomationStepRunStatus::Succeeded) {
            $this->advancePastStep($enrollment, $step);

            return;
        }

        $outcome = $sendEmail->handle($enrollment, $step, $run, $contact);

        if ($outcome['status'] === AutomationStepRunStatus::Succeeded) {
            $providerId = $outcome['result']?->providerMessageId;
            if (! $sendEmail->markSucceededOnce($run, $providerId)) {
                // Another worker already recorded success — do not send again; just advance.
                $this->advancePastStep($enrollment->fresh(), $step);

                return;
            }
            $this->advancePastStep($enrollment->fresh(), $step);

            return;
        }

        if ($outcome['status'] === AutomationStepRunStatus::Skipped) {
            DB::table('automation_step_runs')
                ->where('id', $run->getKey())
                ->update([
                    'status' => AutomationStepRunStatus::Skipped->value,
                    'error_code' => $outcome['error_code'],
                    'error_message' => $outcome['error_message'],
                    'finished_at' => now(),
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
            $this->advancePastStep($enrollment->fresh(), $step);

            return;
        }

        $retryable = (bool) ($outcome['result']?->retryable);
        $maxAttempts = max(1, (int) config('services.messaging.max_attempts', 3));
        $run->refresh();

        if ($retryable && $run->attempt < $maxAttempts) {
            DB::table('automation_step_runs')
                ->where('id', $run->getKey())
                ->update([
                    'status' => AutomationStepRunStatus::Pending->value,
                    'error_code' => $outcome['error_code'],
                    'error_message' => $outcome['error_message'],
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            DB::table('automation_enrollments')
                ->where('id', $enrollment->getKey())
                ->update([
                    'next_run_at' => now('UTC')->addMinutes(1),
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        $this->failStepRun($run, $outcome['error_code'] ?? 'send_failed', $outcome['error_message'] ?? 'Send failed.');
        $this->failEnrollment($enrollment, $outcome['error_code'] ?? 'send_failed');
    }

    private function executeCondition(
        AutomationEnrollment $enrollment,
        AutomationStep $step,
        AutomationStepRun $run,
    ): void {
        $contact = Contact::query()->withTrashed()->find($enrollment->contact_id);
        if ($contact === null) {
            $this->failStepRun($run, 'contact_missing', 'Contact not found.');
            $this->failEnrollment($enrollment, 'contact_missing');

            return;
        }

        $passed = $this->evaluateCondition($step, $contact);
        $goto = $step->conditionGoto($passed);

        DB::table('automation_step_runs')
            ->where('id', $run->getKey())
            ->update([
                'status' => AutomationStepRunStatus::Succeeded->value,
                'finished_at' => now(),
                'lease_expires_at' => null,
                'error_code' => null,
                'error_message' => $passed ? 'condition_true' : 'condition_false',
                'updated_at' => now(),
            ]);

        $this->jumpToGoto($enrollment, $step, $goto);
    }

    private function evaluateCondition(AutomationStep $step, Contact $contact): bool
    {
        if ($contact->trashed()) {
            return false;
        }

        return match ($step->conditionPredicate()) {
            'email_subscribed' => $contact->isMarketingEligible(ConsentChannel::Email),
            'has_tag' => $step->conditionTagId() !== null
                && $contact->tags()->whereKey($step->conditionTagId())->exists(),
            default => false,
        };
    }

    private function jumpToGoto(
        AutomationEnrollment $enrollment,
        AutomationStep $step,
        string|int $goto,
    ): void {
        if ($goto === 'end') {
            $this->completeEnrollment($enrollment);

            return;
        }

        $nextPosition = $goto === 'next' ? $step->position + 1 : (int) $goto;

        $hasTarget = AutomationStep::query()
            ->where('automation_id', $enrollment->automation_id)
            ->where('position', $nextPosition)
            ->exists();

        if (! $hasTarget) {
            $this->completeEnrollment($enrollment);

            return;
        }

        DB::table('automation_enrollments')
            ->where('id', $enrollment->getKey())
            ->where('status', AutomationEnrollmentStatus::Active->value)
            ->update([
                'current_step_position' => $nextPosition,
                'next_run_at' => now('UTC'),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        self::dispatch($enrollment->getKey());
    }

    private function advancePastStep(AutomationEnrollment $enrollment, AutomationStep $step): void
    {
        $this->jumpToGoto($enrollment, $step, 'next');
    }

    private function completeEnrollment(AutomationEnrollment $enrollment): void
    {
        DB::table('automation_enrollments')
            ->where('id', $enrollment->getKey())
            ->where('status', AutomationEnrollmentStatus::Active->value)
            ->update([
                'status' => AutomationEnrollmentStatus::Completed->value,
                'completed_at' => now(),
                'next_run_at' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function failEnrollment(AutomationEnrollment $enrollment, string $reason): void
    {
        DB::table('automation_enrollments')
            ->where('id', $enrollment->getKey())
            ->where('status', AutomationEnrollmentStatus::Active->value)
            ->update([
                'status' => AutomationEnrollmentStatus::Failed->value,
                'cancel_reason' => $reason,
                'next_run_at' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function cancelEnrollment(AutomationEnrollment $enrollment, string $reason): void
    {
        DB::transaction(function () use ($enrollment, $reason) {
            DB::table('automation_enrollments')
                ->where('id', $enrollment->getKey())
                ->where('status', AutomationEnrollmentStatus::Active->value)
                ->update([
                    'status' => AutomationEnrollmentStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                    'next_run_at' => null,
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            DB::table('automation_step_runs')
                ->where('enrollment_id', $enrollment->getKey())
                ->whereIn('status', [
                    AutomationStepRunStatus::Pending->value,
                    AutomationStepRunStatus::Running->value,
                    AutomationStepRunStatus::Failed->value,
                ])
                ->update([
                    'status' => AutomationStepRunStatus::Cancelled->value,
                    'finished_at' => now(),
                    'lease_expires_at' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    private function failStepRun(AutomationStepRun $run, ?string $code, ?string $message): void
    {
        DB::table('automation_step_runs')
            ->where('id', $run->getKey())
            ->update([
                'status' => AutomationStepRunStatus::Failed->value,
                'error_code' => $code,
                'error_message' => $message,
                'finished_at' => now(),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }
}
