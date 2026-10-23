<?php

declare(strict_types=1);

namespace App\Exceptions;

class LostStateRaceException extends ApiException
{
    public function __construct(string $message = 'State change aborted due to lost concurrent race condition.')
    {
        parent::__construct(
            message: $message,
            errorCode: 'LOST_STATE_RACE',
            statusCode: 409
        );
    }
}
