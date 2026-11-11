<?php

namespace App\Support\Messaging\Contracts;

use App\Support\Messaging\SendResult;

interface SmsSender
{
    /**
     * @param  array{to: string, body: string, idempotency_key?: string|null}  $payload
     */
    public function send(array $payload): SendResult;
}
