<?php

namespace App\Support\Messaging;

use App\Support\Messaging\Contracts\SmsSender;

/**
 * SMS provider selection is deferred; mark recipients failed with a clear code.
 */
class DisabledSmsSender implements SmsSender
{
    public function send(array $payload): SendResult
    {
        return SendResult::fail(
            'sms_provider_deferred',
            'SMS sending is not enabled yet. Email delivery via Brevo is available in Phase 9.',
            retryable: false,
        );
    }
}
