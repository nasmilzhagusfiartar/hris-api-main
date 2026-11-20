<?php

namespace App\Exceptions;

class MfaVerificationException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $mfaData
     */
    public function __construct(
        string $message,
        int $statusCode = 422,
        public readonly array $mfaData = []
    ) {
        parent::__construct($message, $statusCode);
    }
}
