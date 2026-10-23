<?php

declare(strict_types=1);

namespace App\Exceptions;

class UnsupportedCoverageException extends ApiException
{
    public function __construct(int $sumInsured)
    {
        parent::__construct(
            message: "Unsupported sum_insured: {$sumInsured}. Supported values are 100000, 300000, 500000.",
            errorCode: 'UNSUPPORTED_COVERAGE',
            statusCode: 422
        );
    }
}
