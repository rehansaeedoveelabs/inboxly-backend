<?php

namespace App\Actions;

use App\Enums\CampaignChannel;
use App\Models\Campaign;
use App\Models\User;
use App\Support\Messaging\Contracts\EmailSender;
use App\Support\Messaging\OutboundMessage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Sends a one-off preview to the authenticated user's email (not the campaign audience).
 */
class SendTestCampaignEmail
{
    public function __construct(private EmailSender $emailSender) {}

    /**
     * @return array{accepted: bool, provider_message_id: ?string, to: string, error_code: ?string, error_message: ?string}
     */
    public function handle(Campaign $campaign, User $user, ?string $overrideTo = null): array
    {
        if ($campaign->channel !== CampaignChannel::Email) {
            throw ValidationException::withMessages([
                'channel' => ['Test send is only available for email campaigns.'],
            ]);
        }

        if ($campaign->isScheduled() || $campaign->status->value === 'sending') {
            throw new ConflictHttpException('Cannot test-send while the campaign is scheduled or sending.');
        }

        $to = $overrideTo !== null && trim($overrideTo) !== ''
            ? mb_strtolower(trim($overrideTo))
            : mb_strtolower(trim((string) $user->email));

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'to' => ['A valid destination email is required for test send.'],
            ]);
        }

        $subject = trim((string) ($campaign->subject ?: 'Test campaign'));
        $html = is_string($campaign->html_body) && $campaign->html_body !== ''
            ? $campaign->html_body
            : '';
        $text = is_string($campaign->body) ? $campaign->body : strip_tags($html);
        if ($html === '' && trim($text) === '') {
            throw ValidationException::withMessages([
                'body' => ['Content is required before test send.'],
            ]);
        }

        $result = $this->emailSender->send(new OutboundMessage(
            to: $to,
            subject: '[Test] '.$subject,
            htmlBody: $html,
            textBody: $text,
            toName: $user->name,
            idempotencyKey: 'test:'.$campaign->getKey().':'.$to.':'.now()->format('YmdHi'),
            tags: ['inboxly-test', 'campaign:'.$campaign->getKey()],
        ));

        return [
            'accepted' => $result->accepted,
            'provider_message_id' => $result->providerMessageId,
            'to' => $to,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
        ];
    }
}
