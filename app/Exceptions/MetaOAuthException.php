<?php

namespace App\Exceptions;

use Exception;
use Throwable;

class MetaOAuthException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $reasonCode,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
