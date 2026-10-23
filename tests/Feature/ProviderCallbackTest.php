<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProviderCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function createSubmittedPendingRequest(): PolicyRequest
    {
        $res = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-'.(string) Str::uuid(),
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1995-05-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'mock_scenario' => 'DELAYED_CALLBACK',
        ]);

        $policy = PolicyRequest::findOrFail($res->json('id'));

        // Submit -> moves to PENDING_CONFIRMATION
        $this->postJson("/api/policy-requests/{$policy->id}/submit")
            ->assertJsonPath('status', 'PENDING_CONFIRMATION');

        return $policy->fresh();
    }

    /**
     * Case C: First delivery applied -> 200 applied, moves status to ISSUED with policy number.
     */
    public function test_first_delivery_applied_successfully(): void
    {
        $policy = $this->createSubmittedPendingRequest();
        $this->assertSame(PolicyStatus::PENDING_CONFIRMATION, $policy->status);

        $eventId = 'evt_'.(string) Str::uuid();
        $policyNumber = 'POL-CONFIRMED-001';

        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => $eventId,
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $policy->provider_reference,
            'client_reference' => $policy->id,
            'policy_number' => $policyNumber,
            'occurred_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'result' => 'applied',
                'event_id' => $eventId,
                'request_status' => 'ISSUED',
            ]);

        $policy->refresh();
        $this->assertSame(PolicyStatus::ISSUED, $policy->status);
        $this->assertSame($policyNumber, $policy->policy_number);

        // Verify provider_events table
        $event = ProviderEvent::where('provider_event_id', $eventId)->firstOrFail();
        $this->assertSame(1, $event->received_count);
        $this->assertSame('APPLIED', $event->processing_result);
    }

    /**
     * Case C: Duplicate callback delivery:
     * Same event_id delivered multiple times -> 200 duplicate, no state change, received_count incremented.
     */
    public function test_duplicate_callback_delivery_returns_duplicate_and_increments_count(): void
    {
        $policy = $this->createSubmittedPendingRequest();
        $eventId = 'evt_dup_'.(string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $policy->provider_reference,
            'client_reference' => $policy->id,
            'policy_number' => 'POL-DUP-001',
            'occurred_at' => now()->toIso8601String(),
        ];

        // Delivery 1 -> applied
        $res1 = $this->postJson('/api/mock-insurer/callback', $payload);
        $res1->assertStatus(200)->assertJsonPath('result', 'applied');

        // Delivery 2 -> duplicate
        $res2 = $this->postJson('/api/mock-insurer/callback', $payload);
        $res2->assertStatus(200)->assertJsonPath('result', 'duplicate');

        // Delivery 3 -> duplicate
        $res3 = $this->postJson('/api/mock-insurer/callback', $payload);
        $res3->assertStatus(200)->assertJsonPath('result', 'duplicate');

        // State remains ISSUED with the original policy number
        $policy->refresh();
        $this->assertSame(PolicyStatus::ISSUED, $policy->status);
        $this->assertSame('POL-DUP-001', $policy->policy_number);

        // Exactly 1 event row exists with received_count = 3
        $event = ProviderEvent::where('provider_event_id', $eventId)->firstOrFail();
        $this->assertSame(3, $event->received_count);
    }

    /**
     * DUPLICATE_CALLBACK scenario end-to-end via flush endpoint:
     * 3 identical deliveries -> 1 applied, 2 duplicates.
     */
    public function test_duplicate_callback_scenario_flush_end_to_end(): void
    {
        $res = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-'.(string) Str::uuid(),
            'agent_id' => 'AGENT-01',
            'customer_name' => 'Duplicate Tester',
            'date_of_birth' => '1995-05-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'mock_scenario' => 'DUPLICATE_CALLBACK',
        ]);

        $policy = PolicyRequest::findOrFail($res->json('id'));

        // Submit -> moves to PENDING_CONFIRMATION, queues 3 duplicate events
        $this->postJson("/api/policy-requests/{$policy->id}/submit")
            ->assertJsonPath('status', 'PENDING_CONFIRMATION');

        // Flush queued events through flush endpoint
        $flushRes = $this->postJson('/api/mock-insurer/events/flush');
        $flushRes->assertStatus(200)
            ->assertJsonPath('flushed_count', 3)
            ->assertJsonPath('results.0.result', 'applied')
            ->assertJsonPath('results.1.result', 'duplicate')
            ->assertJsonPath('results.2.result', 'duplicate');

        $policy->refresh();
        $this->assertSame(PolicyStatus::ISSUED, $policy->status);
    }

    /**
     * Late callback arriving after request is already ISSUED cannot overwrite status or policy_number.
     * When incoming policy_number differs, logs anomaly and records CONFLICT_IGNORED.
     */
    public function test_late_callback_conflicting_policy_number_ignored_without_overwrite(): void
    {
        $policy = $this->createSubmittedPendingRequest();

        // Legitimate event 1 issues policy
        $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'evt_legit_001',
            'event_type' => 'POLICY_ISSUED',
            'client_reference' => $policy->id,
            'policy_number' => 'POL-LEGITIMATE-123',
            'occurred_at' => now()->toIso8601String(),
        ])->assertStatus(200)->assertJsonPath('result', 'applied');

        $policy->refresh();
        $this->assertSame('POL-LEGITIMATE-123', $policy->policy_number);

        // Later conflicting event 2 arrives with DIFFERENT policy number
        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'evt_rogue_002',
            'event_type' => 'POLICY_ISSUED',
            'client_reference' => $policy->id,
            'policy_number' => 'POL-ROGUE-999',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'result' => 'ignored',
                'event_id' => 'evt_rogue_002',
                'request_status' => 'ISSUED',
            ]);

        // Stored policy number must NOT be overwritten!
        $policy->refresh();
        $this->assertSame('POL-LEGITIMATE-123', $policy->policy_number);

        $event = ProviderEvent::where('provider_event_id', 'evt_rogue_002')->firstOrFail();
        $this->assertSame('CONFLICT_IGNORED', $event->processing_result);
    }

    public function test_callback_for_unknown_request_returns_404(): void
    {
        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'evt_unknown_001',
            'event_type' => 'POLICY_ISSUED',
            'client_reference' => (string) Str::uuid(),
            'policy_number' => 'POL-UNKNOWN-001',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(404)
            ->assertJson(['error_code' => 'REQUEST_NOT_FOUND']);
    }

    public function test_callback_for_unsubmitted_request_returns_409_rejected_invalid_state(): void
    {
        // Create request but do NOT submit (still in VALIDATED state)
        $res = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-'.(string) Str::uuid(),
            'agent_id' => 'AGENT-01',
            'customer_name' => 'Jane Doe',
            'date_of_birth' => '1995-05-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        $policy = PolicyRequest::findOrFail($res->json('id'));

        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'evt_early_001',
            'event_type' => 'POLICY_ISSUED',
            'client_reference' => $policy->id,
            'policy_number' => 'POL-EARLY-001',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(409)
            ->assertJson(['error_code' => 'REJECTED_INVALID_STATE']);

        $event = ProviderEvent::where('provider_event_id', 'evt_early_001')->firstOrFail();
        $this->assertSame('REJECTED_INVALID_STATE', $event->processing_result);
    }

    public function test_callback_with_duplicate_policy_number_returns_409(): void
    {
        $policy1 = $this->createSubmittedPendingRequest();
        $policy2 = $this->createSubmittedPendingRequest();

        // Assign policy number to policy 1
        $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'evt_p1',
            'event_type' => 'POLICY_ISSUED',
            'client_reference' => $policy1->id,
            'policy_number' => 'POL-SHARED-123',
            'occurred_at' => now()->toIso8601String(),
        ])->assertStatus(200);

        // Attempting to assign the SAME policy number to policy 2 must be rejected gracefully with 409
        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'evt_p2',
            'event_type' => 'POLICY_ISSUED',
            'client_reference' => $policy2->id,
            'policy_number' => 'POL-SHARED-123',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(409)
            ->assertJson(['error_code' => 'DUPLICATE_POLICY_NUMBER']);
    }

    public function test_callback_validation_rejects_missing_policy_number_on_policy_issued(): void
    {
        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'evt_bad_001',
            'event_type' => 'POLICY_ISSUED',
            'client_reference' => (string) Str::uuid(),
            // missing policy_number
            'occurred_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['policy_number']);
    }
}
