<?php

namespace App\Support\Messaging;

readonly class SendResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $retryable = false,
    ) {}

    public static function ok(?string $providerMessageId = null): self
    {
        return new self(accepted: true, providerMessageId: $providerMessageId);
    }

    public static function fail(string $code, string $message, bool $retryable = false): self
    {
        return new self(
            accepted: false,
            errorCode: $code,
            errorMessage: $message,
            retryable: $retryable,
        );
    }
}
