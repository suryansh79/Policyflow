<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PolicySubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function createValidatedRequest(string $scenario = 'SUCCESS'): PolicyRequest
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-'.(string) Str::uuid(),
            'agent_id' => 'AGENT-01',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1995-05-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'mock_scenario' => $scenario,
        ]);

        return PolicyRequest::findOrFail($response->json('id'));
    }

    /**
     * Standard successful submission: Issues policy number, marks status ISSUED,
     * calls provider exactly once, records attempt row.
     */
    public function test_successful_submission_issues_policy_exactly_once(): void
    {
        $policy = $this->createValidatedRequest('SUCCESS');

        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $policy->id,
                'status' => 'ISSUED',
                'premium' => '225.00',
                'submission' => [
                    'outcome' => 'ISSUED',
                    'provider_called' => true,
                ],
            ]);

        $policy->refresh();
        $this->assertSame(PolicyStatus::ISSUED, $policy->status);
        $this->assertNotNull($policy->policy_number);
        $this->assertNotNull($policy->provider_reference);
        $this->assertSame(1, $policy->attempt_count);

        // Verify provider call count
        $mockPolicy = MockInsurerPolicy::where('client_reference', $policy->id)->firstOrFail();
        $this->assertSame(1, $mockPolicy->issue_call_count);

        // Verify attempt record
        $this->assertSame(1, IssuanceAttempt::where('policy_request_id', $policy->id)->where('type', 'ISSUE')->count());
    }

    /**
     * Repeated submit after ISSUED makes NO provider call and returns ALREADY_ISSUED.
     */
    public function test_repeated_submit_after_issued_makes_no_provider_call(): void
    {
        $policy = $this->createValidatedRequest('SUCCESS');

        // First submit -> ISSUED
        $this->postJson("/api/policy-requests/{$policy->id}/submit")->assertStatus(200);

        $policy->refresh();
        $originalPolicyNumber = $policy->policy_number;

        $mockPolicy = MockInsurerPolicy::where('client_reference', $policy->id)->firstOrFail();
        $this->assertSame(1, $mockPolicy->issue_call_count);

        // Repeated submit -> ALREADY_ISSUED, provider_called: false
        $res2 = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $res2->assertStatus(200)
            ->assertJson([
                'id' => $policy->id,
                'status' => 'ISSUED',
                'policy_number' => $originalPolicyNumber,
                'submission' => [
                    'outcome' => 'ALREADY_ISSUED',
                    'provider_called' => false,
                ],
            ]);

        // Provider was NOT called a second time
        $mockPolicy->refresh();
        $this->assertSame(1, $mockPolicy->issue_call_count);
    }

    /**
     * Case B: TIMEOUT_AFTER_ISSUE with inline reconciliation enabled (default):
     * The insurer commits the policy internally, but client encounters a timeout.
     * System transitions to NEEDS_RECONCILIATION, immediately performs status lookup,
     * discovers the issued policy, and moves to ISSUED without making a second issue call.
     */
    public function test_case_b_timeout_after_issue_inline_reconciliation_resolves_to_issued(): void
    {
        $policy = $this->createValidatedRequest('TIMEOUT_AFTER_ISSUE');

        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $policy->id,
                'status' => 'ISSUED',
                'submission' => [
                    'outcome' => 'ISSUED',
                    'provider_called' => true,
                ],
            ]);

        $policy->refresh();
        $this->assertSame(PolicyStatus::ISSUED, $policy->status);
        $this->assertNotNull($policy->policy_number);
        $this->assertNotNull($policy->provider_reference);

        // Crucial verification: exactly ONE issue call was ever made to the provider!
        $mockPolicy = MockInsurerPolicy::where('client_reference', $policy->id)->firstOrFail();
        $this->assertSame(1, $mockPolicy->issue_call_count);

        // Verify attempts: 1 ISSUE attempt (ended in timeout) + 1 RECONCILE attempt
        $attempts = IssuanceAttempt::where('policy_request_id', $policy->id)->orderBy('id', 'asc')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame('ISSUE', $attempts[0]->type);
        $this->assertSame('NEEDS_RECONCILIATION', $attempts[0]->outcome);
        $this->assertSame('RECONCILE', $attempts[1]->type);
        $this->assertSame('ISSUED', $attempts[1]->outcome);
    }

    /**
     * Case B with inline reconciliation disabled:
     * Request stays in NEEDS_RECONCILIATION until artisan policy:reconcile resolves it.
     */
    public function test_case_b_timeout_reconciliation_deferred_and_resolved_by_artisan_command(): void
    {
        config(['policyflow.reconcile_inline_on_timeout' => false]);

        $policy = $this->createValidatedRequest('TIMEOUT_AFTER_ISSUE');

        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'NEEDS_RECONCILIATION',
                'submission' => [
                    'outcome' => 'NEEDS_RECONCILIATION',
                    'provider_called' => true,
                ],
            ]);

        $policy->refresh();
        $this->assertSame(PolicyStatus::NEEDS_RECONCILIATION, $policy->status);

        // Now run artisan policy:reconcile command
        $this->artisan('policy:reconcile')->assertSuccessful();

        $policy->refresh();
        $this->assertSame(PolicyStatus::ISSUED, $policy->status);
        $this->assertNotNull($policy->policy_number);

        // Exactly 1 issue call was made
        $mockPolicy = MockInsurerPolicy::where('client_reference', $policy->id)->firstOrFail();
        $this->assertSame(1, $mockPolicy->issue_call_count);
    }

    /**
     * Case D: Retry after TEMPORARY_FAILURE:
     * Attempt 1 -> RETRY_PENDING.
     * Attempt 2 -> Success, status ISSUED.
     * Verifies that premium_minor and premium_breakdown remain byte-identical before and after retries!
     */
    public function test_case_d_retry_after_temporary_failure_preserves_immutable_premium(): void
    {
        $policy = $this->createValidatedRequest('TEMPORARY_FAILURE');

        $originalPremiumMinor = $policy->premium_minor;
        $originalBreakdownJson = json_encode($policy->premium_breakdown);

        // Attempt 1: Fails with retriable temporary error -> RETRY_PENDING
        $res1 = $this->postJson("/api/policy-requests/{$policy->id}/submit");
        $res1->assertStatus(200)
            ->assertJson([
                'status' => 'RETRY_PENDING',
                'submission' => [
                    'outcome' => 'RETRY_PENDING',
                    'provider_called' => true,
                ],
            ]);

        $policy->refresh();
        $this->assertSame(PolicyStatus::RETRY_PENDING, $policy->status);
        $this->assertSame(1, $policy->attempt_count);

        // Attempt 2: Retry succeeds -> ISSUED
        $res2 = $this->postJson("/api/policy-requests/{$policy->id}/submit");
        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'ISSUED',
                'submission' => [
                    'outcome' => 'ISSUED',
                    'provider_called' => true,
                ],
            ]);

        $policy->refresh();
        $this->assertSame(PolicyStatus::ISSUED, $policy->status);
        $this->assertNotNull($policy->policy_number);
        $this->assertSame(2, $policy->attempt_count);

        // Spec Requirement: Premium minor and breakdown are byte-identical before and after retries
        $this->assertSame($originalPremiumMinor, $policy->premium_minor);
        $this->assertSame($originalBreakdownJson, json_encode($policy->premium_breakdown));

        // Two issue attempts recorded
        $attempts = IssuanceAttempt::where('policy_request_id', $policy->id)->where('type', 'ISSUE')->get();
        $this->assertCount(2, $attempts);
    }

    /**
     * Retries exhausted: Fails max_submission_attempts times -> transitions to FAILED.
     */
    public function test_retries_exhausted_transitions_to_failed(): void
    {
        config([
            'mock_insurer.temporary_failure_attempts' => 5, // Will fail first 5 times
            'policyflow.max_submission_attempts' => 2,      // Cap at 2 attempts
        ]);

        $policy = $this->createValidatedRequest('TEMPORARY_FAILURE');

        // Attempt 1 -> RETRY_PENDING
        $this->postJson("/api/policy-requests/{$policy->id}/submit")
            ->assertJsonPath('status', 'RETRY_PENDING');

        // Attempt 2 -> Reaches max 2 attempts -> FAILED with RETRIES_EXHAUSTED
        $res2 = $this->postJson("/api/policy-requests/{$policy->id}/submit");
        $res2->assertStatus(200)
            ->assertJson([
                'status' => 'FAILED',
                'submission' => [
                    'outcome' => 'FAILED',
                    'provider_called' => true,
                ],
            ]);

        $policy->refresh();
        $this->assertSame(PolicyStatus::FAILED, $policy->status);
        $this->assertSame('RETRIES_EXHAUSTED', $policy->last_error_code);
    }

    /**
     * Crash safety: Stale SUBMITTED lease is detected as a crashed worker and reconciled.
     */
    public function test_stale_submitted_lease_treated_as_crashed_worker_and_reconciled(): void
    {
        $policy = $this->createValidatedRequest('SUCCESS');

        // Manually place in SUBMITTED state with a submission timestamp 3 minutes ago (exceeding 120s lease)
        $policy->update([
            'status' => PolicyStatus::SUBMITTED,
            'submitted_at' => Carbon::now()->subSeconds(180),
        ]);

        // Submit call should detect stale lease, move to NEEDS_RECONCILIATION, and reconcile
        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(200);

        $policy->refresh();
        // Since provider had no record, reconciliation safely moves it to RETRY_PENDING!
        $this->assertSame(PolicyStatus::RETRY_PENDING, $policy->status);
    }

    /**
     * Submit on non-existent UUID returns 404 JSON.
     */
    public function test_submit_on_unknown_id_returns_404(): void
    {
        $randomUuid = (string) Str::uuid();

        $response = $this->postJson("/api/policy-requests/{$randomUuid}/submit");

        $response->assertStatus(404)
            ->assertJson([
                'error_code' => 'REQUEST_NOT_FOUND',
            ]);
    }

    /**
     * Submit on malformed UUID returns 404 JSON due to whereUuid route constraint.
     */
    public function test_submit_on_malformed_id_returns_404(): void
    {
        $response = $this->postJson('/api/policy-requests/not-a-valid-uuid-string/submit');

        $response->assertStatus(404)
            ->assertJsonStructure(['message', 'error_code']);
    }

    /**
     * Submit on FAILED request returns 409 REQUEST_NOT_SUBMITTABLE.
     */
    public function test_submit_on_failed_request_returns_409(): void
    {
        $policy = $this->createValidatedRequest('PERMANENT_FAILURE');

        // First submit -> FAILED
        $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $policy->refresh();
        $this->assertSame(PolicyStatus::FAILED, $policy->status);

        // Second submit -> 409 Conflict
        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(409)
            ->assertJson([
                'error_code' => 'REQUEST_NOT_SUBMITTABLE',
            ]);
    }
}
