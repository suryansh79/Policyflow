<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

class ApiException extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        protected string $errorCode = 'INTERNAL_ERROR',
        protected int $statusCode = 400,
        protected array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
