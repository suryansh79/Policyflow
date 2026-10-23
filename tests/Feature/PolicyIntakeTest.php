<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\PremiumCalculator;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Repositories\PolicyRequestRepository;
use App\Repositories\PolicyRequestRepositoryInterface;
use App\Services\PolicyRequestIntakeService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'client_request_id' => 'AGT-2026-0001',
        'agent_id' => 'AGENT-17',
        'customer_name' => 'Sample Customer',
        'date_of_birth' => '1998-04-10',
        'destination' => 'INTERNATIONAL',
        'trip_start' => '2026-11-10',
        'trip_end' => '2026-11-14',
        'plan_code' => 'PLUS',
        'sum_insured' => 300000,
    ];

    /**
     * Assessment benchmark: valid new request produces 201, status VALIDATED, premium "225.00",
     * and atomic state history rows null -> RECEIVED -> VALIDATED.
     */
    public function test_valid_new_request_creates_policy_and_returns_201(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload);

        $response->assertStatus(201)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson([
                'client_request_id' => 'AGT-2026-0001',
                'agent_id' => 'AGENT-17',
                'customer_name' => 'Sample Customer',
                'destination' => 'INTERNATIONAL',
                'plan_code' => 'PLUS',
                'sum_insured' => 300000,
                'travel_days' => 5,
                'status' => 'VALIDATED',
                'premium' => '225.00',
                'premium_currency' => 'INR',
            ]);

        $policy = PolicyRequest::where('client_request_id', 'AGT-2026-0001')->firstOrFail();
        $this->assertSame(22500, $policy->premium_minor);

        // Verify atomic state history sequence: null -> RECEIVED, then RECEIVED -> VALIDATED
        $history = PolicyStateHistory::where('policy_request_id', $policy->id)->orderBy('id', 'asc')->get();
        $this->assertCount(2, $history);
        $this->assertNull($history[0]->from_status);
        $this->assertSame('RECEIVED', $history[0]->to_status->value);
        $this->assertSame('RECEIVED', $history[1]->from_status->value);
        $this->assertSame('VALIDATED', $history[1]->to_status->value);
    }

    /**
     * Repeated identical request returns 200, existing resource, no new DB rows,
     * no new history rows, and Idempotent-Replayed: true header.
     */
    public function test_repeated_identical_request_returns_200_safely(): void
    {
        // First submission -> 201
        $res1 = $this->postJson('/api/policy-requests', $this->validPayload);
        $res1->assertStatus(201);
        $id1 = $res1->json('id');

        $this->assertSame(1, PolicyRequest::count());
        $this->assertSame(2, PolicyStateHistory::count());

        // Repeated submission -> 200
        $res2 = $this->postJson('/api/policy-requests', $this->validPayload);

        $res2->assertStatus(200)
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJson([
                'id' => $id1,
                'client_request_id' => 'AGT-2026-0001',
                'status' => 'VALIDATED',
                'premium' => '225.00',
            ]);

        // Verify no additional rows were created in DB or history
        $this->assertSame(1, PolicyRequest::count());
        $this->assertSame(2, PolicyStateHistory::count());
    }

    /**
     * Same agent_id and client_request_id with different payload returns 409 conflict.
     */
    public function test_reused_key_with_different_payload_returns_409(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload)->assertStatus(201);

        $conflictingPayload = $this->validPayload;
        $conflictingPayload['plan_code'] = 'PREMIUM'; // Changed plan

        $response = $this->postJson('/api/policy-requests', $conflictingPayload);

        $response->assertStatus(409)
            ->assertJson([
                'error_code' => 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
            ]);
    }

    /**
     * Same client_request_id for a DIFFERENT agent_id is valid and creates a new request.
     */
    public function test_same_client_request_id_for_different_agent_is_allowed(): void
    {
        $this->postJson('/api/policy-requests', $this->validPayload)->assertStatus(201);

        $differentAgentPayload = $this->validPayload;
        $differentAgentPayload['agent_id'] = 'AGENT-99';

        $response = $this->postJson('/api/policy-requests', $differentAgentPayload);

        $response->assertStatus(201)
            ->assertJson([
                'agent_id' => 'AGENT-99',
                'client_request_id' => 'AGT-2026-0001',
            ]);

        $this->assertSame(2, PolicyRequest::count());
    }

    /**
     * Validation failures for each missing required field return 422 with field errors.
     */
    #[DataProvider('missingRequiredFieldsProvider')]
    public function test_missing_required_fields_return_422(string $field): void
    {
        $payload = $this->validPayload;
        unset($payload[$field]);

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public static function missingRequiredFieldsProvider(): array
    {
        return [
            ['client_request_id'],
            ['agent_id'],
            ['customer_name'],
            ['date_of_birth'],
            ['destination'],
            ['trip_start'],
            ['trip_end'],
            ['plan_code'],
            ['sum_insured'],
        ];
    }

    public function test_validation_rejects_trip_end_before_trip_start(): void
    {
        $payload = $this->validPayload;
        $payload['trip_start'] = '2026-11-14';
        $payload['trip_end'] = '2026-11-10';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_validation_rejects_unsupported_plan_code(): void
    {
        $payload = $this->validPayload;
        $payload['plan_code'] = 'SUPER_PLATINUM';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code'])
            ->assertJsonPath('errors.plan_code.0', 'plan_code must be one of: BASIC, PLUS, PREMIUM.');
    }

    public function test_validation_rejects_unsupported_sum_insured(): void
    {
        $payload = $this->validPayload;
        $payload['sum_insured'] = 999999;

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured'])
            ->assertJsonPath('errors.sum_insured.0', 'sum_insured must be one of: 100000, 300000, 500000.');
    }

    public function test_validation_rejects_invalid_destination(): void
    {
        $payload = $this->validPayload;
        $payload['destination'] = 'SPACE';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination'])
            ->assertJsonPath('errors.destination.0', 'destination must be one of: DOMESTIC, INTERNATIONAL.');
    }

    public function test_validation_rejects_bad_date_formats(): void
    {
        $payload = $this->validPayload;
        $payload['date_of_birth'] = '10/04/1998'; // Not Y-m-d

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_validation_rejects_dob_after_trip_start(): void
    {
        $payload = $this->validPayload;
        $payload['date_of_birth'] = '2026-11-15';
        $payload['trip_start'] = '2026-11-10';
        $payload['trip_end'] = '2026-11-20';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    public function test_intake_validation_returns_422_json_even_without_accept_header(): void
    {
        // Calling post() without Accept header must be intercepted by ForceJsonResponse middleware
        $response = $this->post('/api/policy-requests', []);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['client_request_id', 'agent_id']);
    }

    /**
     * Race condition simulation:
     * Fast-path lookup returns null (as if row doesn't exist yet), but by the time createWithInitialHistory runs,
     * another concurrent worker has already inserted the row.
     * The UniqueConstraintViolationException must be caught, the winning row re-fetched, and 200 returned safely.
     */
    public function test_near_simultaneous_race_simulation_recovers_safely(): void
    {
        // First create the winning record in database
        $intakeService = app(PolicyRequestIntakeService::class);
        $initial = $intakeService->handle($this->validPayload);
        $winnerId = $initial['request']->id;

        // Mock the repository to simulate a lost race:
        // 1. findByAgentAndClientRequestId initially returns null (misses in fast-path)
        // 2. createWithInitialHistory triggers UniqueConstraintViolationException
        // 3. findByAgentAndClientRequestId is called again to re-fetch winner, returning the real winner!
        $realRepo = app(PolicyRequestRepository::class);

        $mockRepo = Mockery::mock(PolicyRequestRepositoryInterface::class);
        $mockRepo->shouldReceive('findByAgentAndClientRequestId')
            ->with('AGENT-17', 'AGT-2026-0001')
            ->andReturn(null, $initial['request']); // null first, then winner on re-fetch

        $mockRepo->shouldReceive('createWithInitialHistory')
            ->andThrow(new UniqueConstraintViolationException(
                'sqlite',
                'INSERT INTO policy_requests ...',
                [],
                new \Exception('UNIQUE constraint failed')
            ));

        $testService = new PolicyRequestIntakeService($mockRepo, app(PremiumCalculator::class));

        $result = $testService->handle($this->validPayload);

        $this->assertTrue($result['is_replayed']);
        $this->assertSame($winnerId, $result['request']->id);
        $this->assertSame(1, PolicyRequest::count());
    }
}
