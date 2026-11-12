<?php

namespace App\Support\Messaging;

readonly class OutboundMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
        public ?string $toName = null,
        public ?string $idempotencyKey = null,
        public array $tags = [],
    ) {}
}
