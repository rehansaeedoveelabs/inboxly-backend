<?php

namespace App\Support\Messaging;

use App\Support\Messaging\Contracts\SmsSender;
use Illuminate\Support\Str;

class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, body: string, idempotency_key?: string|null}> */
    public array $sent = [];

    public function send(array $payload): SendResult
    {
        $this->sent[] = $payload;

        return SendResult::ok('fake_sms_'.Str::lower((string) Str::ulid()));
    }
}
