<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

readonly class PricingInput
{
    public function __construct(
        public string $planCode,
        public int $sumInsured,
        public string $destination,
        public string $dateOfBirth,
        public string $tripStart,
        public string $tripEnd,
    ) {}
}
