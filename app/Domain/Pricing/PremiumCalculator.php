<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Exceptions\ApiException;
use App\Exceptions\UnsupportedCoverageException;
use App\Exceptions\UnsupportedPlanException;
use DateTimeImmutable;

class PremiumCalculator
{
    /**
     * Calculate premium and return a typed PremiumBreakdown value object.
     *
     * Pure integer arithmetic:
     * - Multipliers are represented as integers in hundredths (e.g. 1.10 -> 110, 1.80 -> 180, 1.25 -> 125).
     * - Formula: numerator = rate * days * a * d * c
     * - Premium paise = intdiv(numerator + 5000, 10000) (half-up rounding to nearest paise)
     * - Stored as integer minor units (paise), formatted via integer arithmetic without floats.
     */
    public function calculate(PricingInput $input): PremiumBreakdown
    {
        $rates = config('pricing.base_daily_rates');
        $planCode = strtoupper(trim($input->planCode));
        if (! isset($rates[$planCode])) {
            throw new UnsupportedPlanException($input->planCode);
        }
        $baseDailyRate = (int) $rates[$planCode];

        $coverages = config('pricing.coverage_multipliers');
        if (! isset($coverages[$input->sumInsured])) {
            throw new UnsupportedCoverageException($input->sumInsured);
        }
        $coverageMultiplierHundredths = (int) $coverages[$input->sumInsured];

        $destinations = config('pricing.destination_multipliers');
        $destination = strtoupper(trim($input->destination));
        if (! isset($destinations[$destination])) {
            throw new ApiException(
                message: "Unsupported destination: '{$input->destination}'. Supported destinations are DOMESTIC, INTERNATIONAL.",
                errorCode: 'UNSUPPORTED_DESTINATION',
                statusCode: 422
            );
        }
        $destinationMultiplierHundredths = (int) $destinations[$destination];

        // Parse dates using strict date-only format (!Y-m-d sets time to 00:00:00 UTC)
        $dob = DateTimeImmutable::createFromFormat('!Y-m-d', $input->dateOfBirth);
        $tripStart = DateTimeImmutable::createFromFormat('!Y-m-d', $input->tripStart);
        $tripEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $input->tripEnd);

        if (! $dob || ! $tripStart || ! $tripEnd) {
            throw new ApiException(
                message: 'Invalid date format provided. Expected Y-m-d.',
                errorCode: 'INVALID_DATE_FORMAT',
                statusCode: 422
            );
        }

        if ($tripStart > $tripEnd) {
            throw new ApiException(
                message: 'trip_end must be on or after trip_start.',
                errorCode: 'INVALID_TRAVEL_DATES',
                statusCode: 422
            );
        }

        if ($dob > $tripStart) {
            throw new ApiException(
                message: 'date_of_birth must be on or before trip_start.',
                errorCode: 'INVALID_DATE_OF_BIRTH',
                statusCode: 422
            );
        }

        // Days calculation: inclusive (trip_end - trip_start + 1), minimum 1
        $diff = $tripStart->diff($tripEnd);
        $travelDays = max(1, $diff->days + 1);

        // Age calculation: evaluated on trip_start using DateTimeImmutable::diff()->y
        // (NOT Carbon diffInYears which returns a signed float in Carbon 3)
        $age = $dob->diff($tripStart)->y;

        // Resolve age multiplier based on age brackets
        $ageMultiplierHundredths = $this->resolveAgeMultiplierHundredths($age);

        // Perform decimal-safe integer arithmetic
        $premiumPaise = self::calculatePaise(
            rate: $baseDailyRate,
            days: $travelDays,
            ageMultiplierHundredths: $ageMultiplierHundredths,
            destinationMultiplierHundredths: $destinationMultiplierHundredths,
            coverageMultiplierHundredths: $coverageMultiplierHundredths
        );

        $version = (string) config('pricing.version', 'v1');

        return new PremiumBreakdown(
            minor: $premiumPaise,
            baseDailyRate: $baseDailyRate,
            travelDays: $travelDays,
            age: $age,
            ageMultiplier: $this->formatHundredths($ageMultiplierHundredths),
            destinationMultiplier: $this->formatHundredths($destinationMultiplierHundredths),
            coverageMultiplier: $this->formatHundredths($coverageMultiplierHundredths),
            pricingVersion: $version,
            currency: 'INR'
        );
    }

    /**
     * Resolves the age multiplier integer (in hundredths) for a given age in years.
     */
    protected function resolveAgeMultiplierHundredths(int $age): int
    {
        $brackets = config('pricing.age_multipliers', []);

        foreach ($brackets as $bracket) {
            $min = $bracket['min'];
            $max = $bracket['max'];

            if ($age >= $min && ($max === null || $age <= $max)) {
                return (int) $bracket['multiplier'];
            }
        }

        // Fallback default (18-60 bracket: 100)
        return 100;
    }

    /**
     * Core integer arithmetic calculation with half-up rounding to nearest paise.
     */
    public static function calculatePaise(
        int $rate,
        int $days,
        int $ageMultiplierHundredths,
        int $destinationMultiplierHundredths,
        int $coverageMultiplierHundredths
    ): int {
        // Multipliers are each in hundredths (100 = 1.00)
        // Rate in rupees, result in paise (1 rupee = 100 paise)
        // Combined denominator: 100 * 100 * 100 / 100 = 10,000
        $numerator = $rate * $days * $ageMultiplierHundredths * $destinationMultiplierHundredths * $coverageMultiplierHundredths;

        // Half-up rounding in pure integer arithmetic: add half denominator (5,000) before intdiv
        return intdiv($numerator + 5000, 10000);
    }

    /**
     * Format a hundredths integer (e.g. 110, 180, 100) as a 2-decimal string (e.g. "1.10", "1.80", "1.00")
     * without float conversion.
     */
    protected function formatHundredths(int $hundredths): string
    {
        $units = intdiv($hundredths, 100);
        $fraction = $hundredths % 100;

        return sprintf('%d.%02d', $units, $fraction);
    }
}
