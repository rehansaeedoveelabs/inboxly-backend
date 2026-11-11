<?php

namespace App\Support\Messaging;

use App\Support\Messaging\Contracts\EmailSender;
use Illuminate\Support\Facades\Http;
use Throwable;

class BrevoEmailSender implements EmailSender
{
    public function send(OutboundMessage $message): SendResult
    {
        if (! (bool) config('services.brevo.sending_enabled')) {
            return SendResult::fail(
                'sending_disabled',
                'Brevo sending is disabled. Set BREVO_SENDING_ENABLED=true to send real email.',
                retryable: false,
            );
        }

        $apiKey = config('services.brevo.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return SendResult::fail('missing_api_key', 'BREVO_API_KEY is not configured.', retryable: false);
        }

        $senderEmail = config('services.brevo.sender_email');
        $senderName = config('services.brevo.sender_name') ?: config('app.name');
        if (! is_string($senderEmail) || trim($senderEmail) === '') {
            return SendResult::fail(
                'missing_sender',
                'BREVO_SENDER_EMAIL is required for shared-domain sending.',
                retryable: false,
            );
        }

        $payload = [
            'sender' => [
                'email' => $senderEmail,
                'name' => $senderName,
            ],
            'to' => [[
                'email' => $message->to,
                'name' => $message->toName ?: $message->to,
            ]],
            'subject' => $message->subject,
            'htmlContent' => $message->htmlBody !== '' ? $message->htmlBody : nl2br(e($message->textBody)),
            'textContent' => $message->textBody !== '' ? $message->textBody : strip_tags($message->htmlBody),
        ];

        if ($message->tags !== []) {
            $payload['tags'] = array_values(array_slice($message->tags, 0, 10));
        }

        $headers = [
            'api-key' => $apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ];
        if (is_string($message->idempotencyKey) && $message->idempotencyKey !== '') {
            $headers['idempotencyKey'] = $message->idempotencyKey;
        }

        try {
            $response = Http::baseUrl((string) config('services.brevo.base_url'))
                ->timeout((int) config('services.brevo.timeout_seconds', 20))
                ->withHeaders($headers)
                ->post('/smtp/email', $payload);
        } catch (Throwable $e) {
            return SendResult::fail('network_error', $e->getMessage(), retryable: true);
        }

        if ($response->successful()) {
            $messageId = $response->json('messageId');

            return SendResult::ok(is_string($messageId) ? $messageId : null);
        }

        $status = $response->status();
        $code = (string) ($response->json('code') ?? 'http_'.$status);
        $msg = (string) ($response->json('message') ?? $response->body());
        $retryable = $status === 429 || $status >= 500;

        return SendResult::fail($code, mb_substr($msg, 0, 900), retryable: $retryable);
    }
}
