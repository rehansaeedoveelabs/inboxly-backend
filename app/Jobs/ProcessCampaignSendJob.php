<?php

namespace App\Jobs;

use App\Enums\CampaignChannel;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Suppression;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\Contracts\SmsSender;
use App\Support\Messaging\OutboundMessage;
use App\Support\Messaging\SendResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessCampaignSendJob implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT_SECONDS = 300;

    public int $tries = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(public readonly string $campaignId) {}

    public function handle(EmailSender $emailSender, SmsSender $smsSender): void
    {
        $campaign = Campaign::query()->find($this->campaignId);
        if ($campaign === null || $campaign->status !== CampaignStatus::Sending) {
            return;
        }

        $batchSize = max(1, (int) config('services.messaging.batch_size', 100));
        $maxAttempts = max(1, (int) config('services.messaging.max_attempts', 3));

        $recipients = CampaignRecipient::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('status', CampaignRecipientStatus::Pending)
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        if ($recipients->isEmpty()) {
            $this->finalize($campaign);

            return;
        }

        foreach ($recipients as $recipient) {
            $this->processRecipient($campaign, $recipient, $emailSender, $smsSender, $maxAttempts);
        }

        // Continue until pending recipients are exhausted.
        if (CampaignRecipient::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('status', CampaignRecipientStatus::Pending)
            ->exists()) {
            self::dispatch($campaign->getKey());

            return;
        }

        $this->finalize($campaign->fresh());
    }

    private function processRecipient(
        Campaign $campaign,
        CampaignRecipient $recipient,
        EmailSender $emailSender,
        SmsSender $smsSender,
        int $maxAttempts,
    ): void {
        // Claim pending → queued to avoid duplicate workers sending the same row.
        $claimed = DB::table('campaign_recipients')
            ->where('id', $recipient->getKey())
            ->where('status', CampaignRecipientStatus::Pending->value)
            ->update([
                'status' => CampaignRecipientStatus::Queued->value,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $recipient->refresh();

        $contact = Contact::query()->withTrashed()->find($recipient->contact_id);
        if ($contact === null || $contact->trashed() || ! $contact->isMarketingEligible($campaign->channel->toConsentChannel())) {
            $this->markRecipient($recipient, CampaignRecipientStatus::Skipped, 'ineligible', 'Contact no longer eligible.');

            return;
        }

        if ($recipient->address !== '' && Suppression::query()
            ->where('store_id', $campaign->store_id)
            ->where('channel', $campaign->channel)
            ->where('address', $recipient->address)
            ->exists()) {
            $this->markRecipient($recipient, CampaignRecipientStatus::Skipped, 'suppressed', 'Address suppressed.');

            return;
        }

        $dailyCap = (int) config('services.messaging.daily_cap_per_store', 0);
        if ($dailyCap > 0) {
            $sentToday = CampaignRecipient::query()
                ->whereHas('campaign', fn ($q) => $q->where('store_id', $campaign->store_id))
                ->where('status', CampaignRecipientStatus::Sent)
                ->whereDate('sent_at', now()->toDateString())
                ->count();
            if ($sentToday >= $dailyCap) {
                $this->markRecipient($recipient, CampaignRecipientStatus::Failed, 'daily_cap', 'Store daily send cap reached.');

                return;
            }
        }

        try {
            $result = $campaign->channel === CampaignChannel::Email
                ? $emailSender->send(new OutboundMessage(
                    to: $recipient->address,
                    subject: (string) $campaign->subject,
                    htmlBody: (string) ($campaign->html_body ?: ''),
                    textBody: (string) ($campaign->body ?: ''),
                    toName: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: null,
                    idempotencyKey: $campaign->getKey().':'.$recipient->getKey().':'.($recipient->attempts + 1),
                    tags: ['campaign:'.$campaign->getKey(), 'store:'.$campaign->store_id],
                ))
                : $smsSender->send([
                    'to' => $recipient->address,
                    'body' => (string) $campaign->body,
                    'idempotency_key' => $campaign->getKey().':'.$recipient->getKey(),
                ]);
        } catch (Throwable $e) {
            $result = SendResult::fail('exception', $e->getMessage(), retryable: true);
        }

        $attempts = $recipient->attempts + 1;
        $recipient->attempts = $attempts;

        if ($result->accepted) {
            $recipient->forceFill([
                'status' => CampaignRecipientStatus::Sent,
                'provider' => $campaign->channel === CampaignChannel::Email
                    ? (string) config('services.messaging.email_driver', 'brevo')
                    : (string) config('services.messaging.sms_driver', 'disabled'),
                'provider_message_id' => $result->providerMessageId,
                'error_code' => null,
                'error_message' => null,
                'sent_at' => now(),
            ])->save();

            return;
        }

        if ($result->retryable && $attempts < $maxAttempts) {
            $recipient->forceFill([
                'status' => CampaignRecipientStatus::Pending,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ])->save();

            return;
        }

        $this->markRecipient(
            $recipient,
            CampaignRecipientStatus::Failed,
            $result->errorCode ?? 'send_failed',
            $result->errorMessage ?? 'Send failed.',
        );
    }

    private function markRecipient(
        CampaignRecipient $recipient,
        CampaignRecipientStatus $status,
        string $code,
        string $message,
    ): void {
        $recipient->forceFill([
            'status' => $status,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 900),
        ])->save();
    }

    private function finalize(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::Sending) {
            return;
        }

        $sent = $campaign->recipients()->where('status', CampaignRecipientStatus::Sent)->count();
        $failed = $campaign->recipients()->where('status', CampaignRecipientStatus::Failed)->count();
        $skipped = $campaign->recipients()->where('status', CampaignRecipientStatus::Skipped)->count();
        $pending = $campaign->recipients()->whereIn('status', [
            CampaignRecipientStatus::Pending,
            CampaignRecipientStatus::Queued,
        ])->count();

        if ($pending > 0) {
            return;
        }

        $status = match (true) {
            $sent > 0 && $failed === 0 => CampaignStatus::Sent,
            $sent > 0 && $failed > 0 => CampaignStatus::PartiallySent,
            $sent === 0 && $failed > 0 => CampaignStatus::Failed,
            default => CampaignStatus::Sent, // zero audience / all skipped
        };

        $campaign->forceFill([
            'status' => $status,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'sent_at' => now(),
            'scheduled_at' => null,
        ])->save();
    }
}
