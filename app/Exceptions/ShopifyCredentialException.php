<?php

namespace App\Exceptions;

use RuntimeException;

class ShopifyCredentialException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly bool $permanent = false,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
