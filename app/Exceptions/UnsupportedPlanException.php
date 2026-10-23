<?php

declare(strict_types=1);

namespace App\Exceptions;

class UnsupportedPlanException extends ApiException
{
    public function __construct(string $planCode)
    {
        parent::__construct(
            message: "Unsupported plan code: '{$planCode}'. Supported plans are BASIC, PLUS, PREMIUM.",
            errorCode: 'UNSUPPORTED_PLAN',
            statusCode: 422
        );
    }
}
