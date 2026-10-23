<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Exceptions\ImmutableFieldException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_duplicate_agent_and_client_request_id(): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'agent_id' => 'AGENT-101',
            'client_request_id' => 'REQ-ABC-001',
            'request_fingerprint' => hash('sha256', 'canonical-sample'),
            'customer_name' => 'Alice Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium_minor' => 6000,
            'premium_currency' => 'INR',
            'premium_breakdown' => json_encode(['rate' => 12]),
            'status' => PolicyStatus::VALIDATED->value,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('policy_requests')->insert($payload);

        $this->expectException(QueryException::class);

        // Attempt second insert with same agent_id and client_request_id but different UUID
        $duplicatePayload = $payload;
        $duplicatePayload['id'] = (string) Str::uuid();

        DB::table('policy_requests')->insert($duplicatePayload);
    }

    public function test_database_rejects_duplicate_policy_number(): void
    {
        $payload1 = [
            'id' => (string) Str::uuid(),
            'agent_id' => 'AGENT-101',
            'client_request_id' => 'REQ-001',
            'request_fingerprint' => hash('sha256', 'sample-1'),
            'customer_name' => 'Alice Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium_minor' => 6000,
            'premium_currency' => 'INR',
            'premium_breakdown' => json_encode([]),
            'status' => PolicyStatus::ISSUED->value,
            'policy_number' => 'POL-UNIQUE-999',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('policy_requests')->insert($payload1);

        $this->expectException(QueryException::class);

        $payload2 = $payload1;
        $payload2['id'] = (string) Str::uuid();
        $payload2['client_request_id'] = 'REQ-002'; // different client request id

        DB::table('policy_requests')->insert($payload2);
    }

    public function test_database_rejects_duplicate_provider_event(): void
    {
        $eventPayload = [
            'provider' => 'mock',
            'provider_event_id' => 'EVT-999',
            'event_type' => 'POLICY_ISSUED',
            'payload' => json_encode(['foo' => 'bar']),
            'received_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('provider_events')->insert($eventPayload);

        $this->expectException(QueryException::class);

        DB::table('provider_events')->insert($eventPayload);
    }

    public function test_policy_request_model_prevents_mutation_of_immutable_snapshot_fields(): void
    {
        $request = PolicyRequest::create([
            'agent_id' => 'AGENT-01',
            'client_request_id' => 'REQ-SNAP-01',
            'request_fingerprint' => hash('sha256', 'snap-test'),
            'customer_name' => 'John Original',
            'date_of_birth' => '1995-05-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-10',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'travel_days' => 10,
            'premium_minor' => 45000,
            'premium_currency' => 'INR',
            'premium_breakdown' => ['multiplier' => 1.8],
            'status' => PolicyStatus::VALIDATED,
        ]);

        $this->expectException(ImmutableFieldException::class);
        $request->update(['premium_minor' => 30000]);
    }

    public function test_policy_state_history_model_is_append_only(): void
    {
        $request = PolicyRequest::create([
            'agent_id' => 'AGENT-01',
            'client_request_id' => 'REQ-HIST-01',
            'request_fingerprint' => hash('sha256', 'hist-test'),
            'customer_name' => 'Jane History',
            'date_of_birth' => '1992-02-02',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium_minor' => 6000,
            'premium_currency' => 'INR',
            'premium_breakdown' => [],
            'status' => PolicyStatus::VALIDATED,
        ]);

        $history = PolicyStateHistory::create([
            'policy_request_id' => $request->id,
            'from_status' => null,
            'to_status' => PolicyStatus::RECEIVED,
            'reason' => 'Intake received',
            'source' => 'API',
        ]);

        // Attempting to update state history must throw ImmutableFieldException
        try {
            $history->update(['reason' => 'Modified reason']);
            $this->fail('Expected ImmutableFieldException when updating history row');
        } catch (ImmutableFieldException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // Attempting to delete state history must throw ImmutableFieldException
        try {
            $history->delete();
            $this->fail('Expected ImmutableFieldException when deleting history row');
        } catch (ImmutableFieldException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
