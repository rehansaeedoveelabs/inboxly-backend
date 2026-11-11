<?php

namespace App\Exceptions;

use RuntimeException;

class ShopifyOAuthException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
