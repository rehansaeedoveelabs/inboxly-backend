<?php

namespace App\Actions;

use App\Enums\CampaignChannel;
use App\Enums\CampaignRecipientStatus;
use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\SuppressionReason;
use App\Models\BrevoWebhookEvent;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies authenticated Brevo transactional webhook facts.
 * Payload shapes: https://developers.brevo.com/docs/transactional-webhooks
 */
class ProcessBrevoWebhookEvent
{
    public function __construct(private UpdateContactConsents $updateConsents) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{processed: bool, duplicate: bool, ignored: bool}
     */
    public function handle(array $payload): array
    {
        $event = $this->normalizeEvent($payload['event'] ?? null);
        $email = isset($payload['email']) && is_string($payload['email'])
            ? Contact::normalizeEmail($payload['email'])
            : null;
        $messageId = $this->normalizeMessageId($payload['message-id'] ?? $payload['messageId'] ?? null);

        if ($event === null) {
            return ['processed' => false, 'duplicate' => false, 'ignored' => true];
        }

        $dedupeKey = hash('sha256', implode('|', [
            $event,
            $email ?? '',
            $messageId ?? '',
            (string) ($payload['ts_event'] ?? $payload['ts'] ?? ''),
            (string) ($payload['id'] ?? ''),
        ]));

        try {
            $record = BrevoWebhookEvent::query()->create([
                'dedupe_key' => $dedupeKey,
                'event' => $event,
                'email' => $email,
                'provider_message_id' => $messageId,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['processed' => false, 'duplicate' => true, 'ignored' => false];
        }

        $recipient = $this->findRecipient($messageId, $email);
        if ($recipient !== null) {
            $record->campaign_recipient_id = $recipient->getKey();
        }

        match ($event) {
            'delivered' => $this->applyDelivered($recipient),
            'hard_bounce', 'blocked', 'invalid' => $this->applyHardFailure(
                $recipient,
                $email,
                SuppressionReason::HardBounce,
                CampaignRecipientStatus::Bounced,
                $event,
            ),
            'spam' => $this->applyHardFailure(
                $recipient,
                $email,
                SuppressionReason::Complaint,
                CampaignRecipientStatus::Failed,
                $event,
            ),
            'unsubscribed' => $this->applyUnsubscribe($recipient, $email),
            'soft_bounce', 'deferred' => null, // provider facts only; no permanent failure
            default => null,
        };

        $record->processed_at = now();
        $record->save();

        return ['processed' => true, 'duplicate' => false, 'ignored' => false];
    }

    private function normalizeEvent(mixed $event): ?string
    {
        if (! is_string($event) || $event === '') {
            return null;
        }

        $map = [
            'delivered' => 'delivered',
            'hard_bounce' => 'hard_bounce',
            'hardbounce' => 'hard_bounce',
            'soft_bounce' => 'soft_bounce',
            'softbounce' => 'soft_bounce',
            'blocked' => 'blocked',
            'invalid' => 'invalid',
            'spam' => 'spam',
            'unsubscribed' => 'unsubscribed',
            'deferred' => 'deferred',
            'sent' => 'sent',
            'request' => 'request',
        ];

        $key = strtolower(str_replace(['-', ' '], '_', $event));

        return $map[$key] ?? null;
    }

    private function normalizeMessageId(mixed $messageId): ?string
    {
        if (! is_string($messageId) || trim($messageId) === '') {
            return null;
        }

        return trim($messageId, " \t\n\r\0\x0B<>");
    }

    private function findRecipient(?string $messageId, ?string $email): ?CampaignRecipient
    {
        if ($messageId !== null) {
            $byId = CampaignRecipient::query()
                ->where(function ($query) use ($messageId) {
                    $query->where('provider_message_id', $messageId)
                        ->orWhere('provider_message_id', '<'.$messageId.'>');
                })
                ->orderByDesc('id')
                ->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        // Without a provider message id, do not guess across stores by email alone.
        return null;
    }

    private function applyDelivered(?CampaignRecipient $recipient): void
    {
        // Provider fact only — recipient already marked sent on accept.
        if ($recipient === null) {
            return;
        }
    }

    private function applyHardFailure(
        ?CampaignRecipient $recipient,
        ?string $email,
        SuppressionReason $reason,
        CampaignRecipientStatus $status,
        string $event,
    ): void {
        DB::transaction(function () use ($recipient, $email, $reason, $status, $event) {
            if ($recipient !== null) {
                $recipient->forceFill([
                    'status' => $status,
                    'error_code' => $event,
                    'error_message' => 'Provider event: '.$event,
                    'lease_expires_at' => null,
                ])->save();

                $storeId = $recipient->campaign?->store_id;
                $address = $recipient->address !== '' ? $recipient->address : $email;
                if ($storeId && $address) {
                    $this->upsertSuppression($storeId, $address, $reason, 'brevo:'.$event);
                }
            } elseif ($email !== null) {
                // Without a recipient match we cannot safely pick a store — skip suppression.
            }
        });
    }

    private function applyUnsubscribe(?CampaignRecipient $recipient, ?string $email): void
    {
        // Only mutate contacts/suppressions when tied to a known campaign recipient (store-scoped).
        if ($recipient === null) {
            return;
        }

        DB::transaction(function () use ($recipient, $email) {
            $recipient->forceFill([
                'status' => CampaignRecipientStatus::Unsubscribed,
                'error_code' => 'unsubscribed',
                'error_message' => 'Provider event: unsubscribed',
                'lease_expires_at' => null,
            ])->save();

            $contact = Contact::query()->find($recipient->contact_id);
            $storeId = $recipient->campaign?->store_id;
            $address = $recipient->address !== '' ? $recipient->address : $email;

            if ($storeId && $address) {
                $this->upsertSuppression($storeId, $address, SuppressionReason::Unsubscribe, 'brevo:unsubscribed');
            }

            if ($contact !== null) {
                $this->updateConsents->handle($contact, [[
                    'channel' => ConsentChannel::Email->value,
                    'status' => ConsentStatus::Unsubscribed->value,
                    'source' => 'brevo_webhook',
                ]], 'brevo_webhook');
            }
        });
    }

    private function upsertSuppression(
        string $storeId,
        string $address,
        SuppressionReason $reason,
        string $source,
    ): void {
        Suppression::query()->updateOrCreate(
            [
                'store_id' => $storeId,
                'channel' => CampaignChannel::Email,
                'address' => mb_strtolower(trim($address)),
            ],
            [
                'reason' => $reason,
                'source' => $source,
            ],
        );
    }
}
