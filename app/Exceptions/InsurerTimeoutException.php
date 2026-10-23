<?php

declare(strict_types=1);

namespace App\Exceptions;

class InsurerTimeoutException extends ApiException
{
    public function __construct(string $message = 'External insurer request timed out. Outcome is indeterminate.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'INSURER_TIMEOUT',
            statusCode: 504
        );
    }
}
