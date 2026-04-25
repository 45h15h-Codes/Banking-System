<?php

namespace App\Exceptions;

use RuntimeException;

class ServiceClientException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'UPSTREAM_REQUEST_FAILED',
        private readonly int $status = 502,
        private readonly mixed $details = null,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): mixed
    {
        return $this->details;
    }
}

