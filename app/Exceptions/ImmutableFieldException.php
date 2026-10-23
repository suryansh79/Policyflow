<?php

declare(strict_types=1);

namespace App\Exceptions;

class ImmutableFieldException extends ApiException
{
    public function __construct(string $message = 'Modification of immutable policy snapshot fields is strictly forbidden.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'IMMUTABLE_FIELD_MODIFICATION',
            statusCode: 422
        );
    }
}
