<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

readonly class PremiumBreakdown
{
    public function __construct(
        public int $minor,
        public int $baseDailyRate,
        public int $travelDays,
        public int $age,
        public string $ageMultiplier,
        public string $destinationMultiplier,
        public string $coverageMultiplier,
        public string $pricingVersion,
        public string $currency = 'INR',
    ) {}

    /**
     * Formats the premium amount in INR as a strict 2-decimal string
     * using integer division and modulo (never number_format or float conversion).
     */
    public function formatted(): string
    {
        $rupees = intdiv($this->minor, 100);
        $paise = $this->minor % 100;

        return sprintf('%d.%02d', $rupees, $paise);
    }

    /**
     * Returns the serializable breakdown array for JSON persistence and API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base_daily_rate' => $this->baseDailyRate,
            'travel_days' => $this->travelDays,
            'age' => $this->age,
            'age_multiplier' => $this->ageMultiplier,
            'destination_multiplier' => $this->destinationMultiplier,
            'coverage_multiplier' => $this->coverageMultiplier,
            'pricing_version' => $this->pricingVersion,
        ];
    }
}
