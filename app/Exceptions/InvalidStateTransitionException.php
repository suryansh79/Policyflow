<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PolicyStatus;

class InvalidStateTransitionException extends ApiException
{
    public function __construct(
        PolicyStatus|string $from,
        PolicyStatus|string $to,
        string $message = ''
    ) {
        $fromStr = is_string($from) ? $from : $from->value;
        $toStr = is_string($to) ? $to : $to->value;

        $msg = $message !== ''
            ? $message
            : "Invalid state transition from '{$fromStr}' to '{$toStr}'.";

        parent::__construct(
            message: $msg,
            errorCode: 'INVALID_STATE_TRANSITION',
            statusCode: 409,
            details: [
                'from_status' => $fromStr,
                'to_status' => $toStr,
            ]
        );
    }
}
