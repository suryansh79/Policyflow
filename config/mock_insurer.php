<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Mock Insurer Configuration
    |--------------------------------------------------------------------------
    */

    // Number of temporary failure attempts before success
    'temporary_failure_attempts' => (int) env('MOCK_INSURER_TEMPORARY_FAILURE_ATTEMPTS', 1),

    // Number of duplicate callback events queued in DUPLICATE_CALLBACK scenario
    'duplicate_callback_deliveries' => (int) env('MOCK_INSURER_DUPLICATE_CALLBACK_DELIVERIES', 3),

    // Whether mock scenario override via intake request is allowed (disabled in strict production)
    'mock_scenario_allowed' => (bool) env('MOCK_SCENARIO_ALLOWED', env('APP_ENV') !== 'production'),
];
