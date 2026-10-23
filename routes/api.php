<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MockInsurerController;
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

// Policy Requests Intake & Submission
Route::post('/policy-requests', [PolicyRequestController::class, 'store']);
Route::post('/policy-requests/{id}/submit', [PolicyRequestController::class, 'submit'])->whereUuid('id');

// Mock Insurer Inspection and Direct Test Endpoints
Route::prefix('mock-insurer')->group(function () {
    Route::post('/policies', [MockInsurerController::class, 'issue']);
    Route::get('/policies/{client_reference}', [MockInsurerController::class, 'lookup'])->whereUuid('client_reference');
    Route::get('/events', [MockInsurerController::class, 'events']);
});
