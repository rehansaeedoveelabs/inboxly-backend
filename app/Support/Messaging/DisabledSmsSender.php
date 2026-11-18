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
            'sms_provider_disabled',
            'SMS sending is disabled. Set MESSAGING_SMS_DRIVER=fake or vonage to enable.',
            retryable: false,
        );
    }
}
