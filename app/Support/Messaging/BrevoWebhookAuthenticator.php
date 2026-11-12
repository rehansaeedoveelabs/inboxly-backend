<?php

namespace App\Support\Messaging;

use Illuminate\Http\Request;

/**
 * Brevo does not HMAC-sign webhook bodies. Authentication is configured on the
 * webhook itself (bearer / basic / custom headers).
 *
 * Authoritative docs: https://developers.brevo.com/docs/secured-webhooks
 *
 * Inboxly accepts Authorization: Bearer <BREVO_WEBHOOK_SECRET> only.
 */
class BrevoWebhookAuthenticator
{
    public function authenticate(Request $request): bool
    {
        $expected = config('services.brevo.webhook_secret');
        if (! is_string($expected) || trim($expected) === '') {
            return false;
        }

        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return false;
        }

        return hash_equals(trim($expected), $token);
    }
}
