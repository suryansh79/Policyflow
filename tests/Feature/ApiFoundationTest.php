<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api')->group(function () {
            Route::post('/test-validation', function () {
                request()->validate([
                    'field' => 'required|string',
                ]);

                return response()->json(['status' => 'valid']);
            });
        });
    }

    public function test_api_health_endpoint_returns_json(): void
    {
        $response = $this->get('/api/health');

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'status' => 'ok',
                'service' => 'policyflow',
            ]);
    }

    public function test_validation_failure_returns_json_422_without_accept_header(): void
    {
        // Notice: No 'Accept: application/json' header provided
        $response = $this->post('/api/test-validation', []);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['field']);
    }

    public function test_unknown_api_endpoint_returns_json_404(): void
    {
        $response = $this->get('/api/unknown-non-existent-route');

        $response->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'error_code']);
    }

    public function test_invalid_http_method_returns_json_405(): void
    {
        $response = $this->delete('/api/health');

        $response->assertStatus(405)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message', 'error_code']);
    }
}
