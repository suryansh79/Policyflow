<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | PolicyFlow Engine Settings
    |--------------------------------------------------------------------------
    */

    // Number of seconds before a request stuck in SUBMITTED is considered a crashed worker lease
    'submission_lease_seconds' => (int) env('POLICYFLOW_SUBMISSION_LEASE_SECONDS', 120),

    // Maximum number of submission attempts before transitioning to FAILED (RETRIES_EXHAUSTED)
    'max_submission_attempts' => (int) env('POLICYFLOW_MAX_SUBMISSION_ATTEMPTS', 3),

    // Whether to immediately attempt provider reconciliation inline on a timeout exception
    'reconcile_inline_on_timeout' => (bool) env('POLICYFLOW_RECONCILE_INLINE_ON_TIMEOUT', true),
];
