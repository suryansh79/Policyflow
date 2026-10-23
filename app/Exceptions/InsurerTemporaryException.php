<?php

declare(strict_types=1);

namespace App\Exceptions;

class InsurerTemporaryException extends ApiException
{
    public function __construct(string $message = 'External insurer service temporarily unavailable. Request may be retried.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'INSURER_TEMPORARY_FAILURE',
            statusCode: 503
        );
    }
}
