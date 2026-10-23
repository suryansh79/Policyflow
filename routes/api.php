<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
| All routes are prefixed with /api and return JSON responses.
|
*/

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'policyflow',
        'timestamp' => now()->toIso8601String(),
    ]);
});
