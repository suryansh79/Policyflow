<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Services\PolicyRequestIntakeService;
use App\Services\SubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyQueryTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-REQ-001',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-05-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    public function test_show_detail_includes_audit_trail_in_order_and_attempts(): void
    {
        $intake = app(PolicyRequestIntakeService::class);
        $result = $intake->handle($this->validPayload());
        $policyRequestId = $result['request']->id;

        // Submit to make an attempt and advance state to ISSUED
        $submissionService = app(SubmissionService::class);
        $submissionService->submit($policyRequestId);

        $response = $this->getJson("/api/policy-requests/{$policyRequestId}");

        $response->assertStatus(200);

        // Assert flat single resource shape (no outer data wrapper)
        $response->assertJsonPath('id', $policyRequestId);
        $response->assertJsonPath('client_request_id', 'AGT-REQ-001');
        $response->assertJsonPath('agent_id', 'AGENT-01');
        $response->assertJsonPath('status', PolicyStatus::ISSUED->value);
        $response->assertJsonPath('premium', '225.00');
        $response->assertJsonPath('premium_currency', 'INR');
        $response->assertJsonStructure([
            'id',
            'client_request_id',
            'agent_id',
            'customer_name',
            'date_of_birth',
            'destination',
            'trip_start',
            'trip_end',
            'plan_code',
            'sum_insured',
            'travel_days',
            'status',
            'premium',
            'premium_currency',
            'premium_breakdown',
            'provider_reference',
            'policy_number',
            'attempt_count',
            'last_error_code',
            'last_error_message',
            'issued_at',
            'created_at',
            'updated_at',
            'audit_trail',
            'attempts',
        ]);

        $auditTrail = $response->json('audit_trail');
        $this->assertIsArray($auditTrail);
        $this->assertCount(4, $auditTrail);

        // Verify oldest-first order and state progression:
        // 1: null -> RECEIVED
        // 2: RECEIVED -> VALIDATED
        // 3: VALIDATED -> SUBMITTED
        // 4: SUBMITTED -> ISSUED
        $this->assertNull($auditTrail[0]['from_status']);
        $this->assertSame(PolicyStatus::RECEIVED->value, $auditTrail[0]['to_status']);

        $this->assertSame(PolicyStatus::RECEIVED->value, $auditTrail[1]['from_status']);
        $this->assertSame(PolicyStatus::VALIDATED->value, $auditTrail[1]['to_status']);

        $this->assertSame(PolicyStatus::VALIDATED->value, $auditTrail[2]['from_status']);
        $this->assertSame(PolicyStatus::SUBMITTED->value, $auditTrail[2]['to_status']);

        $this->assertSame(PolicyStatus::SUBMITTED->value, $auditTrail[3]['from_status']);
        $this->assertSame(PolicyStatus::ISSUED->value, $auditTrail[3]['to_status']);

        $attempts = $response->json('attempts');
        $this->assertIsArray($attempts);
        $this->assertCount(1, $attempts);
        $this->assertSame('ISSUE', $attempts[0]['type']);
        $this->assertSame(1, $attempts[0]['sequence']);
        $this->assertSame('ISSUED', $attempts[0]['outcome']);
    }

    public function test_show_unknown_id_returns_json_404(): void
    {
        $response = $this->getJson('/api/policy-requests/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'The requested policy request was not found.',
                'error_code' => 'REQUEST_NOT_FOUND',
            ]);
    }

    public function test_show_malformed_id_returns_json_404(): void
    {
        $response = $this->getJson('/api/policy-requests/not-a-valid-uuid');

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'The requested endpoint or resource was not found.',
                'error_code' => 'REQUEST_NOT_FOUND',
            ]);
    }

    public function test_list_returns_standard_paginator_shape_and_omits_audit_trail_and_attempts(): void
    {
        $intake = app(PolicyRequestIntakeService::class);
        $intake->handle($this->validPayload(['client_request_id' => 'REQ-1']));
        $intake->handle($this->validPayload(['client_request_id' => 'REQ-2']));
        $intake->handle($this->validPayload(['client_request_id' => 'REQ-3']));

        $response = $this->getJson('/api/policy-requests');

        $response->assertStatus(200);

        // Paginator structure
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'client_request_id',
                    'agent_id',
                    'customer_name',
                    'date_of_birth',
                    'destination',
                    'trip_start',
                    'trip_end',
                    'plan_code',
                    'sum_insured',
                    'travel_days',
                    'status',
                    'premium',
                    'premium_currency',
                    'premium_breakdown',
                    'provider_reference',
                    'policy_number',
                    'attempt_count',
                    'last_error_code',
                    'last_error_message',
                    'issued_at',
                    'created_at',
                    'updated_at',
                ],
            ],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(3, $data);

        // Verify audit_trail and attempts are omitted in the list view
        $this->assertArrayNotHasKey('audit_trail', $data[0]);
        $this->assertArrayNotHasKey('attempts', $data[0]);
    }

    public function test_list_pagination_and_per_page_bounds(): void
    {
        $intake = app(PolicyRequestIntakeService::class);
        for ($i = 1; $i <= 5; $i++) {
            $intake->handle($this->validPayload(['client_request_id' => "PAGINATE-{$i}"]));
        }

        // Page 1 with per_page=2
        $response = $this->getJson('/api/policy-requests?per_page=2&page=1');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(3, $response->json('meta.last_page'));

        // Page 2 with per_page=2
        $response2 = $this->getJson('/api/policy-requests?per_page=2&page=2');
        $response2->assertStatus(200);
        $this->assertCount(2, $response2->json('data'));
        $this->assertSame(2, $response2->json('meta.current_page'));

        // Page 3 with per_page=2
        $response3 = $this->getJson('/api/policy-requests?per_page=2&page=3');
        $response3->assertStatus(200);
        $this->assertCount(1, $response3->json('data'));
    }

    public function test_list_per_page_validation_bounds(): void
    {
        $responseMin = $this->getJson('/api/policy-requests?per_page=0');
        $responseMin->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $responseMax = $this->getJson('/api/policy-requests?per_page=101');
        $responseMax->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $responseType = $this->getJson('/api/policy-requests?per_page=abc');
        $responseType->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_list_status_filter(): void
    {
        $intake = app(PolicyRequestIntakeService::class);
        $req1 = $intake->handle($this->validPayload(['client_request_id' => 'FILTER-1']))['request'];
        $req2 = $intake->handle($this->validPayload(['client_request_id' => 'FILTER-2']))['request'];

        // Submit req2 to advance to ISSUED
        app(SubmissionService::class)->submit($req2->id);

        // Filter ISSUED
        $responseIssued = $this->getJson('/api/policy-requests?status=ISSUED');
        $responseIssued->assertStatus(200);
        $dataIssued = $responseIssued->json('data');
        $this->assertCount(1, $dataIssued);
        $this->assertSame($req2->id, $dataIssued[0]['id']);
        $this->assertSame(PolicyStatus::ISSUED->value, $dataIssued[0]['status']);

        // Filter VALIDATED
        $responseValidated = $this->getJson('/api/policy-requests?status=VALIDATED');
        $responseValidated->assertStatus(200);
        $dataValidated = $responseValidated->json('data');
        $this->assertCount(1, $dataValidated);
        $this->assertSame($req1->id, $dataValidated[0]['id']);
        $this->assertSame(PolicyStatus::VALIDATED->value, $dataValidated[0]['status']);
    }

    public function test_list_invalid_status_returns_422(): void
    {
        $response = $this->getJson('/api/policy-requests?status=NON_EXISTENT_STATUS');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_list_agent_id_filter(): void
    {
        $intake = app(PolicyRequestIntakeService::class);
        $intake->handle($this->validPayload(['agent_id' => 'AGENT-ALPHA', 'client_request_id' => 'ALPHA-1']));
        $intake->handle($this->validPayload(['agent_id' => 'AGENT-ALPHA', 'client_request_id' => 'ALPHA-2']));
        $intake->handle($this->validPayload(['agent_id' => 'AGENT-BETA', 'client_request_id' => 'BETA-1']));

        $response = $this->getJson('/api/policy-requests?agent_id=AGENT-ALPHA');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertSame('AGENT-ALPHA', $item['agent_id']);
        }
    }
}
