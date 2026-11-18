<?php

namespace App\Actions\Automations;

use App\Enums\AutomationStepRunStatus;
use App\Enums\ConsentChannel;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStep;
use App\Models\AutomationStepRun;
use App\Models\Contact;
use App\Models\Suppression;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\OutboundMessage;
use App\Support\Messaging\SendResult;
use Illuminate\Support\Facades\DB;

/**
 * Individual-contact marketing send for automation steps.
 * Reuses EmailSender; never opens a second provider client.
 */
class SendAutomationEmail
{
    public function __construct(private EmailSender $emailSender) {}

    /**
     * @return array{status: AutomationStepRunStatus, result: ?SendResult, error_code: ?string, error_message: ?string}
     */
    public function handle(
        AutomationEnrollment $enrollment,
        AutomationStep $step,
        AutomationStepRun $run,
        Contact $contact,
    ): array {
        if ($run->status === AutomationStepRunStatus::Succeeded) {
            return [
                'status' => AutomationStepRunStatus::Succeeded,
                'result' => null,
                'error_code' => null,
                'error_message' => null,
            ];
        }

        if ($contact->trashed()) {
            return $this->skip('contact_deleted', 'Contact is deleted.');
        }

        if (! $contact->isMarketingEligible(ConsentChannel::Email)) {
            return $this->skip('ineligible', 'Contact is not marketing eligible for email.');
        }

        $address = strtolower(trim((string) $contact->email));
        if ($address === '') {
            return $this->skip('missing_address', 'Contact has no email address.');
        }

        if (Suppression::query()
            ->where('store_id', $enrollment->store_id)
            ->where('channel', 'email')
            ->where('address', $address)
            ->exists()) {
            return $this->skip('suppressed', 'Address is suppressed.');
        }

        $subject = $step->emailSubject();
        if ($subject === '') {
            return [
                'status' => AutomationStepRunStatus::Failed,
                'result' => null,
                'error_code' => 'missing_subject',
                'error_message' => 'Email subject is required.',
            ];
        }

        $textBody = $step->emailBody();
        $htmlBody = $step->emailHtmlBody();
        if ($textBody === '' && $htmlBody === '') {
            return [
                'status' => AutomationStepRunStatus::Failed,
                'result' => null,
                'error_code' => 'missing_body',
                'error_message' => 'Email body is required.',
            ];
        }

        $idempotencyKey = AutomationStepRun::idempotencyKeyFor(
            $enrollment->getKey(),
            $step->getKey(),
        );

        $result = $this->emailSender->send(new OutboundMessage(
            to: $address,
            subject: $subject,
            htmlBody: $htmlBody !== '' ? $htmlBody : nl2br(e($textBody)),
            textBody: $textBody !== '' ? $textBody : strip_tags($htmlBody),
            toName: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: null,
            idempotencyKey: $idempotencyKey,
            tags: [
                'automation:'.$enrollment->automation_id,
                'enrollment:'.$enrollment->getKey(),
                'store:'.$enrollment->store_id,
            ],
        ));

        if ($result->accepted) {
            return [
                'status' => AutomationStepRunStatus::Succeeded,
                'result' => $result,
                'error_code' => null,
                'error_message' => null,
            ];
        }

        return [
            'status' => AutomationStepRunStatus::Failed,
            'result' => $result,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ];
    }

    /**
     * @return array{status: AutomationStepRunStatus, result: ?SendResult, error_code: ?string, error_message: ?string}
     */
    private function skip(string $code, string $message): array
    {
        return [
            'status' => AutomationStepRunStatus::Skipped,
            'result' => null,
            'error_code' => $code,
            'error_message' => $message,
        ];
    }

    /**
     * Atomically mark a step run succeeded only once (guards duplicate job delivery).
     */
    public function markSucceededOnce(AutomationStepRun $run, ?string $providerMessageId): bool
    {
        $updated = DB::table('automation_step_runs')
            ->where('id', $run->getKey())
            ->where('status', '!=', AutomationStepRunStatus::Succeeded->value)
            ->update([
                'status' => AutomationStepRunStatus::Succeeded->value,
                'provider_message_id' => $providerMessageId,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => now(),
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $updated === 1;
    }
}
