<?php

namespace App\Support\Messaging;

use App\Support\Messaging\Contracts\SmsSender;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Real Vonage (Nexmo) SMS via the classic SMS API.
 *
 * Docs: https://developer.vonage.com/en/api/sms
 * Requires MESSAGING_SMS_DRIVER=vonage and VONAGE_SMS_SENDING_ENABLED=true.
 */
class VonageSmsSender implements SmsSender
{
    public function send(array $payload): SendResult
    {
        if (! (bool) config('services.vonage.sending_enabled')) {
            return SendResult::fail(
                'sending_disabled',
                'Vonage SMS sending is disabled. Set VONAGE_SMS_SENDING_ENABLED=true to send real SMS.',
                retryable: false,
            );
        }

        $apiKey = config('services.vonage.api_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            return SendResult::fail('missing_api_key', 'VONAGE_API_KEY is not configured.', retryable: false);
        }

        $apiSecret = config('services.vonage.api_secret');
        if (! is_string($apiSecret) || trim($apiSecret) === '') {
            return SendResult::fail('missing_api_secret', 'VONAGE_API_SECRET is not configured.', retryable: false);
        }

        $from = config('services.vonage.from');
        if (! is_string($from) || trim($from) === '') {
            return SendResult::fail('missing_from', 'VONAGE_FROM is required for SMS sending.', retryable: false);
        }

        $to = isset($payload['to']) && is_string($payload['to']) ? trim($payload['to']) : '';
        $body = isset($payload['body']) && is_string($payload['body']) ? $payload['body'] : '';
        if ($to === '' || $body === '') {
            return SendResult::fail('invalid_payload', 'SMS requires a non-empty to and body.', retryable: false);
        }

        $toDigits = $this->normalizeDestination($to);
        if ($toDigits === '') {
            return SendResult::fail('invalid_destination', 'SMS destination must be a valid phone number.', retryable: false);
        }

        $request = [
            'api_key' => trim($apiKey),
            'api_secret' => trim($apiSecret),
            'to' => $toDigits,
            'from' => trim($from),
            'text' => $body,
            'type' => 'text',
        ];

        $idempotency = $payload['idempotency_key'] ?? null;
        if (is_string($idempotency) && $idempotency !== '') {
            // Vonage client-ref max length is 40 characters.
            $request['client-ref'] = mb_substr($idempotency, 0, 40);
        }

        $baseUrl = rtrim((string) config('services.vonage.base_url', 'https://rest.nexmo.com'), '/');

        try {
            $response = Http::baseUrl($baseUrl)
                ->timeout((int) config('services.vonage.timeout_seconds', 20))
                ->acceptJson()
                ->asJson()
                ->post('/sms/json', $request);
        } catch (Throwable $e) {
            return SendResult::fail('network_error', $e->getMessage(), retryable: true);
        }

        if ($response->status() === 429 || $response->status() >= 500) {
            return SendResult::fail(
                'http_'.$response->status(),
                mb_substr((string) ($response->json('error_text') ?? $response->body()), 0, 900),
                retryable: true,
            );
        }

        if (! $response->successful()) {
            $msg = (string) ($response->json('error_text') ?? $response->json('message') ?? $response->body());

            return SendResult::fail(
                'http_'.$response->status(),
                mb_substr($msg, 0, 900),
                retryable: false,
            );
        }

        $messages = $response->json('messages');
        if (! is_array($messages) || $messages === []) {
            return SendResult::fail(
                'malformed_response',
                'Vonage SMS response did not include messages.',
                retryable: true,
            );
        }

        $first = $messages[0];
        if (! is_array($first)) {
            return SendResult::fail('malformed_response', 'Vonage SMS message entry was invalid.', retryable: true);
        }

        $status = isset($first['status']) ? (string) $first['status'] : '';
        $messageId = isset($first['message-id']) && is_string($first['message-id'])
            ? $first['message-id']
            : null;

        // Status "0" means submitted successfully.
        if ($status === '0') {
            return SendResult::ok($messageId);
        }

        $errorText = isset($first['error-text']) && is_string($first['error-text'])
            ? $first['error-text']
            : 'Vonage rejected the SMS.';
        $retryable = in_array($status, ['1', '5', '9'], true); // throttled / internal / missing params sometimes transient

        return SendResult::fail(
            'vonage_status_'.$status,
            mb_substr($errorText, 0, 900),
            retryable: $retryable,
        );
    }

    /**
     * Vonage expects digits only (international format without leading +).
     */
    private function normalizeDestination(string $to): string
    {
        $trimmed = trim($to);
        if (str_starts_with($trimmed, '+')) {
            $trimmed = substr($trimmed, 1);
        }

        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        return $digits;
    }
}
