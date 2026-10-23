<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
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

Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
