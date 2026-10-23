<?php

declare(strict_types=1);

namespace App\Exceptions;

class InsurerPermanentException extends ApiException
{
    public function __construct(string $message = 'External insurer permanently rejected policy issuance.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'INSURER_PERMANENT_FAILURE',
            statusCode: 422
        );
    }
}
