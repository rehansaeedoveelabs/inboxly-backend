<?php

namespace App\Support\Messaging;

use App\Support\Messaging\Contracts\EmailSender;
use Illuminate\Support\Str;

/**
 * Test/CI driver — never contacts a real provider.
 */
class FakeEmailSender implements EmailSender
{
    /** @var list<OutboundMessage> */
    public array $sent = [];

    public function send(OutboundMessage $message): SendResult
    {
        $this->sent[] = $message;

        return SendResult::ok('fake_'.Str::lower((string) Str::ulid()));
    }
}
