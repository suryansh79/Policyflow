<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\LostStateRaceException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\PolicyStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PolicyStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private PolicyStateMachine $stateMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateMachine = new PolicyStateMachine;
    }

    /**
     * Exhaustive test over all 64 (from, to) combinations.
     * Asserts that exactly the 18 allowed transitions succeed and all 46 invalid transitions throw.
     */
    #[DataProvider('fullTransitionMatrixProvider')]
    public function test_full_state_transition_matrix(PolicyStatus $from, PolicyStatus $to, bool $isAllowed): void
    {
        $this->assertSame(
            $isAllowed,
            $from->canTransitionTo($to),
            "Expected canTransitionTo({$from->value} -> {$to->value}) to be ".($isAllowed ? 'true' : 'false')
        );

        // Create a model with the starting status
        $request = PolicyRequest::create([
            'agent_id' => 'AGENT-SM',
            'client_request_id' => 'REQ-SM-'.$from->value.'-'.$to->value,
            'request_fingerprint' => hash('sha256', 'matrix-test-'.$from->value.'-'.$to->value),
            'customer_name' => 'Matrix Tester',
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
            'status' => $from,
        ]);

        if ($isAllowed) {
            $updated = $this->stateMachine->transition(
                request: $request,
                to: $to,
                reason: "Valid test transition {$from->value} -> {$to->value}",
                source: 'SYSTEM'
            );

            $this->assertSame($to, $updated->status);

            // Verify state history written atomically
            $lastHistory = PolicyStateHistory::where('policy_request_id', $request->id)->latest('id')->first();
            $this->assertNotNull($lastHistory);
            $this->assertSame($from, $lastHistory->from_status);
            $this->assertSame($to, $lastHistory->to_status);
        } else {
            $this->expectException(InvalidStateTransitionException::class);
            $this->stateMachine->transition(
                request: $request,
                to: $to,
                reason: "Invalid test transition {$from->value} -> {$to->value}",
                source: 'SYSTEM'
            );
        }
    }

    public static function fullTransitionMatrixProvider(): array
    {
        $allStatuses = PolicyStatus::cases();
        $cases = [];

        // Exact 18 allowed transitions as specified in Section 5
        $allowedMap = [
            PolicyStatus::RECEIVED->value => [
                PolicyStatus::VALIDATED->value,
                PolicyStatus::FAILED->value,
            ],
            PolicyStatus::VALIDATED->value => [
                PolicyStatus::SUBMITTED->value,
                PolicyStatus::FAILED->value,
            ],
            PolicyStatus::SUBMITTED->value => [
                PolicyStatus::ISSUED->value,
                PolicyStatus::PENDING_CONFIRMATION->value,
                PolicyStatus::RETRY_PENDING->value,
                PolicyStatus::NEEDS_RECONCILIATION->value,
                PolicyStatus::FAILED->value,
            ],
            PolicyStatus::RETRY_PENDING->value => [
                PolicyStatus::SUBMITTED->value,
                PolicyStatus::FAILED->value,
            ],
            PolicyStatus::PENDING_CONFIRMATION->value => [
                PolicyStatus::ISSUED->value,
                PolicyStatus::FAILED->value,
                PolicyStatus::NEEDS_RECONCILIATION->value,
            ],
            PolicyStatus::NEEDS_RECONCILIATION->value => [
                PolicyStatus::ISSUED->value,
                PolicyStatus::FAILED->value,
                PolicyStatus::PENDING_CONFIRMATION->value,
                PolicyStatus::RETRY_PENDING->value,
            ],
            PolicyStatus::ISSUED->value => [],
            PolicyStatus::FAILED->value => [],
        ];

        foreach ($allStatuses as $from) {
            foreach ($allStatuses as $to) {
                $isAllowed = in_array($to->value, $allowedMap[$from->value], true);
                $cases["{$from->value} -> {$to->value}"] = [$from, $to, $isAllowed];
            }
        }

        return $cases;
    }

    /**
     * Specific regression test: ISSUED -> SUBMITTED must always be rejected.
     */
    public function test_issued_to_submitted_regression_is_strictly_rejected(): void
    {
        $request = PolicyRequest::create([
            'agent_id' => 'AGENT-REG',
            'client_request_id' => 'REQ-REG-01',
            'request_fingerprint' => hash('sha256', 'reg-test'),
            'customer_name' => 'Regression Test',
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
            'status' => PolicyStatus::ISSUED,
            'policy_number' => 'POL-REG-001',
        ]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->transition(
            request: $request,
            to: PolicyStatus::SUBMITTED,
            reason: 'Illegal regression attempt',
            source: 'SYSTEM'
        );
    }

    /**
     * Compare-and-Swap (CAS) update handles lost races gracefully.
     */
    public function test_cas_update_lost_race_handling(): void
    {
        $request = PolicyRequest::create([
            'agent_id' => 'AGENT-CAS',
            'client_request_id' => 'REQ-CAS-01',
            'request_fingerprint' => hash('sha256', 'cas-test'),
            'customer_name' => 'CAS Tester',
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
        ]);

        // Simulate another thread updating status in the background before our CAS executes
        DB::table('policy_requests')
            ->where('id', $request->id)
            ->update(['status' => PolicyStatus::SUBMITTED->value]);

        $this->expectException(LostStateRaceException::class);

        // Attempting to transition from VALIDATED while DB is already in SUBMITTED
        $this->stateMachine->transition(
            request: $request, // Model still holds stale status VALIDATED
            to: PolicyStatus::SUBMITTED,
            reason: 'Concurrent claim',
            source: 'SUBMIT'
        );
    }
}
