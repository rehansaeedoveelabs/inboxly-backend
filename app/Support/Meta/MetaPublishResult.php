<?php

namespace App\Support\Meta;

final class MetaPublishResult
{
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $providerPostId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
        public readonly bool $simulated = false,
        public readonly bool $ambiguous = false,
    ) {}

    /**
     * Confirmed acceptance with a usable provider post ID (never null/blank).
     */
    public static function ok(string $providerPostId, bool $simulated = false): self
    {
        return new self(
            accepted: true,
            providerPostId: $providerPostId,
            simulated: $simulated,
        );
    }

    public static function fail(string $code, string $message, bool $retryable = false, bool $simulated = false): self
    {
        return new self(
            accepted: false,
            errorCode: $code,
            errorMessage: mb_substr($message, 0, 900),
            retryable: $retryable,
            simulated: $simulated,
        );
    }

    /**
     * Request may have reached Meta, but the client did not receive a definitive
     * success or rejection. Must not auto-retry publishing.
     */
    public static function ambiguous(string $code, string $message, bool $simulated = false): self
    {
        return new self(
            accepted: false,
            errorCode: $code,
            errorMessage: mb_substr($message, 0, 900),
            retryable: false,
            simulated: $simulated,
            ambiguous: true,
        );
    }
}
