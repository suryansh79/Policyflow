<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Pricing Formula Configuration
    |--------------------------------------------------------------------------
    |
    | All rates and multipliers are stored as integers to ensure decimal-safe,
    | integer-only arithmetic (paise).
    | Base daily rate in INR (paise = rate * 100).
    | Multipliers are stored as integers in hundredths (e.g. 1.10 -> 110, 1.80 -> 180).
    |
    */

    'version' => 'v1',

    // Base daily rates in INR
    'base_daily_rates' => [
        'BASIC' => 12,
        'PLUS' => 20,
        'PREMIUM' => 30,
    ],

    // Age multipliers: evaluated against age on trip_start
    // Multiplier * 100
    'age_multipliers' => [
        ['min' => 0, 'max' => 17, 'multiplier' => 110],   // 1.10
        ['min' => 18, 'max' => 60, 'multiplier' => 100],  // 1.00
        ['min' => 61, 'max' => 70, 'multiplier' => 150],  // 1.50
        ['min' => 71, 'max' => null, 'multiplier' => 200], // 2.00
    ],

    // Destination multipliers * 100
    'destination_multipliers' => [
        'DOMESTIC' => 100,       // 1.00
        'INTERNATIONAL' => 180,  // 1.80
    ],

    // Coverage (sum_insured) multipliers * 100
    'coverage_multipliers' => [
        100000 => 100,  // 1.00
        300000 => 125,  // 1.25
        500000 => 150,  // 1.50
    ],
];
