<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\InsurerGateway;
use App\Enums\PolicyStatus;
use App\Exceptions\InsurerPermanentException;
use App\Exceptions\InsurerTemporaryException;
use App\Exceptions\InsurerTimeoutException;
use App\Mock\Insurer\MockInsurerService;
use App\Models\MockInsurerEvent;
use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MockInsurerTest extends TestCase
{
    use RefreshDatabase;

    private MockInsurerService $mockService;

    private InsurerGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockService = app(MockInsurerService::class);
        $this->gateway = app(InsurerGateway::class);
    }

    public function test_success_scenario_issues_policy_and_increments_call_count(): void
    {
        $uuid = (string) Str::uuid();

        // Call 1
        $response1 = $this->gateway->issue($uuid, ['plan' => 'PLUS'], 'SUCCESS');
        $this->assertTrue($response1->isIssued());
        $this->assertNotNull($response1->policyNumber);
        $this->assertNotNull($response1->providerReference);

        $dbPolicy = MockInsurerPolicy::where('client_reference', $uuid)->firstOrFail();
        $this->assertSame(1, $dbPolicy->issue_call_count);
        $this->assertSame('ISSUED', $dbPolicy->status);
        $this->assertSame($response1->policyNumber, $dbPolicy->policy_number);

        // Call 2 (idempotent replay returns same policy, but increments issue_call_count)
        $response2 = $this->gateway->issue($uuid, ['plan' => 'PLUS'], 'SUCCESS');
        $this->assertSame($response1->policyNumber, $response2->policyNumber);
        $this->assertSame($response1->providerReference, $response2->providerReference);

        $dbPolicy->refresh();
        $this->assertSame(2, $dbPolicy->issue_call_count);
    }

    public function test_temporary_failure_scenario_fails_then_succeeds(): void
    {
        $uuid = (string) Str::uuid();

        // Attempt 1: should throw InsurerTemporaryException
        try {
            $this->gateway->issue($uuid, ['plan' => 'PLUS'], 'TEMPORARY_FAILURE');
            $this->fail('Expected InsurerTemporaryException on first attempt');
        } catch (InsurerTemporaryException $e) {
            $this->assertStringContainsString('temporary', $e->getMessage());
        }

        $dbPolicy = MockInsurerPolicy::where('client_reference', $uuid)->firstOrFail();
        $this->assertSame(1, $dbPolicy->issue_call_count);

        // Attempt 2: threshold exceeded, should succeed
        $response = $this->gateway->issue($uuid, ['plan' => 'PLUS'], 'TEMPORARY_FAILURE');
        $this->assertTrue($response->isIssued());
        $this->assertNotNull($response->policyNumber);

        $dbPolicy->refresh();
        $this->assertSame(2, $dbPolicy->issue_call_count);
        $this->assertSame('ISSUED', $dbPolicy->status);
    }

    public function test_timeout_after_issue_creates_policy_then_throws_timeout_exception(): void
    {
        $uuid = (string) Str::uuid();

        // Attempt issue: should throw InsurerTimeoutException
        try {
            $this->gateway->issue($uuid, ['plan' => 'PLUS'], 'TIMEOUT_AFTER_ISSUE');
            $this->fail('Expected InsurerTimeoutException');
        } catch (InsurerTimeoutException $e) {
            $this->assertSame('INSURER_TIMEOUT', $e->getErrorCode());
        }

        // But internally at insurer, policy WAS created and issued
        $lookup = $this->gateway->lookup($uuid);
        $this->assertTrue($lookup->found);
        $this->assertTrue($lookup->isIssued());
        $this->assertNotNull($lookup->policyNumber);
        $this->assertNotNull($lookup->providerReference);
    }

    public function test_delayed_callback_scenario_queues_single_event(): void
    {
        $uuid = (string) Str::uuid();

        $response = $this->gateway->issue($uuid, ['plan' => 'BASIC'], 'DELAYED_CALLBACK');
        $this->assertTrue($response->isPending());
        $this->assertNull($response->policyNumber);
        $this->assertNotNull($response->providerReference);

        $event = MockInsurerEvent::latest('id')->firstOrFail();
        $this->assertSame(1, $event->deliveries_remaining);
        $this->assertSame('POLICY_ISSUED', $event->payload['event_type']);
        $this->assertSame($uuid, $event->payload['client_reference']);
    }

    public function test_duplicate_callback_scenario_queues_event_with_three_deliveries(): void
    {
        $uuid = (string) Str::uuid();

        $response = $this->gateway->issue($uuid, ['plan' => 'PREMIUM'], 'DUPLICATE_CALLBACK');
        $this->assertTrue($response->isPending());

        $event = MockInsurerEvent::latest('id')->firstOrFail();
        $this->assertSame(3, $event->deliveries_remaining);
    }

    public function test_permanent_failure_scenario_rejects(): void
    {
        $uuid = (string) Str::uuid();

        $this->expectException(InsurerPermanentException::class);
        $this->gateway->issue($uuid, ['plan' => 'BASIC'], 'PERMANENT_FAILURE');
    }

    public function test_lookup_for_non_existent_reference_returns_not_found(): void
    {
        $lookup = $this->gateway->lookup((string) Str::uuid());
        $this->assertFalse($lookup->found);
        $this->assertTrue($lookup->isNotFound());
    }

    public function test_set_scenario_artisan_command_updates_scenario(): void
    {
        $policy = PolicyRequest::create([
            'agent_id' => 'AGENT-01',
            'client_request_id' => 'REQ-CMD-01',
            'request_fingerprint' => hash('sha256', 'cmd-test'),
            'customer_name' => 'Cmd Tester',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium_minor' => 6000,
            'premium_currency' => 'INR',
            'premium_breakdown' => [],
            'status' => PolicyStatus::VALIDATED,
            'mock_scenario' => 'SUCCESS',
        ]);

        $this->artisan('mock-insurer:set-scenario', [
            'request_id' => $policy->id,
            'scenario' => 'TIMEOUT_AFTER_ISSUE',
        ])->assertSuccessful();

        $policy->refresh();
        $this->assertSame('TIMEOUT_AFTER_ISSUE', $policy->mock_scenario);
    }
}
