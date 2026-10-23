<?php

declare(strict_types=1);

namespace App\Mock\Insurer;

use App\DTO\InsurerIssueResponse;
use App\DTO\InsurerLookupResponse;
use App\Exceptions\ApiException;
use App\Exceptions\InsurerPermanentException;
use App\Exceptions\InsurerTemporaryException;
use App\Exceptions\InsurerTimeoutException;
use App\Models\MockInsurerEvent;
use App\Models\MockInsurerPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Submit an issuance request to the mock insurer.
     *
     * Architectural note:
     * 1. In-process design: PHP CLI web server (php artisan serve) is single-threaded;
     *    in-process execution avoids self-calling HTTP deadlocks during local testing.
     * 2. Transaction isolation: The mock insurer's internal state (policy record, call counts)
     *    is committed in its own transaction before any simulated transport exception is thrown.
     *    In real life, the insurer's database commits independently of the client's network timeout.
     *
     * @param  array<string, mixed>  $payload
     */
    public function issue(string $clientReference, array $payload, ?string $scenario = null): InsurerIssueResponse
    {
        // Outcome action to execute after mock insurer state is safely committed
        $action = null;

        $response = DB::transaction(function () use ($clientReference, $scenario, &$action) {
            $policy = MockInsurerPolicy::where('client_reference', $clientReference)->first();

            // Track scenario (explicit parameter, existing record scenario, or default SUCCESS)
            $effectiveScenario = strtoupper($scenario ?? $policy?->scenario ?? 'SUCCESS');

            if (! $policy) {
                $policy = MockInsurerPolicy::create([
                    'client_reference' => $clientReference,
                    'scenario' => $effectiveScenario,
                    'issue_call_count' => 0,
                    'status' => 'PENDING',
                ]);
            }

            // The mock is idempotent by client_reference, but increments issue_call_count on EVERY call
            // so tests can verify whether unnecessary calls were made.
            $policy->increment('issue_call_count');
            $policy->refresh();
            $callCount = $policy->issue_call_count;

            // If already final and issued from a prior successful call, return existing policy
            if ($policy->status === 'ISSUED' && $effectiveScenario !== 'TIMEOUT_AFTER_ISSUE') {
                return new InsurerIssueResponse(
                    status: 'ISSUED',
                    providerReference: $policy->provider_reference,
                    policyNumber: $policy->policy_number,
                    rawResponse: $policy->toArray()
                );
            }

            /*
            |--------------------------------------------------------------------------
            | SCENARIO HANDLERS (Single obvious match block for live reviewer modification)
            |--------------------------------------------------------------------------
            */
            return match ($effectiveScenario) {
                'SUCCESS' => $this->handleSuccessScenario($policy),

                'TEMPORARY_FAILURE' => $this->handleTemporaryFailureScenario($policy, $callCount, $action),

                'TIMEOUT_AFTER_ISSUE' => $this->handleTimeoutAfterIssueScenario($policy, $action),

                'DELAYED_CALLBACK' => $this->handleDelayedCallbackScenario($policy, $clientReference, deliveries: 1),

                'DUPLICATE_CALLBACK' => $this->handleDuplicateCallbackScenario($policy, $clientReference),

                'PERMANENT_FAILURE' => $this->handlePermanentFailureScenario($policy, $action),

                default => throw new ApiException("Unknown mock insurer scenario: '{$effectiveScenario}'.", 'INVALID_MOCK_SCENARIO', 400),
            };
        });

        // If scenario specifies a transport/network exception, throw it after mock commit
        if (is_callable($action)) {
            $action();
        }

        return $response;
    }

    /**
     * Look up policy status by PolicyFlow client_reference UUID.
     * Enables safe reconciliation after timeouts when provider_reference was never returned.
     */
    public function lookup(string $clientReference): InsurerLookupResponse
    {
        $policy = MockInsurerPolicy::where('client_reference', $clientReference)->first();

        if (! $policy) {
            return new InsurerLookupResponse(
                found: false,
                status: 'NOT_FOUND',
                providerReference: null,
                policyNumber: null,
                rawResponse: ['result' => 'NOT_FOUND']
            );
        }

        return new InsurerLookupResponse(
            found: true,
            status: $policy->status,
            providerReference: $policy->provider_reference,
            policyNumber: $policy->policy_number,
            rawResponse: $policy->toArray()
        );
    }

    /**
     * Scenario: SUCCESS - Immediate policy issuance.
     */
    protected function handleSuccessScenario(MockInsurerPolicy $policy): InsurerIssueResponse
    {
        $providerRef = $policy->provider_reference ?? ('PRV-'.strtoupper(Str::random(10)));
        $policyNumber = $policy->policy_number ?? ('POL-'.strtoupper(Str::random(12)));

        $policy->update([
            'status' => 'ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => $policyNumber,
        ]);

        return new InsurerIssueResponse(
            status: 'ISSUED',
            providerReference: $providerRef,
            policyNumber: $policyNumber,
            rawResponse: $policy->fresh()->toArray()
        );
    }

    /**
     * Scenario: TEMPORARY_FAILURE - Retriable failure for N attempts, then succeeds.
     */
    protected function handleTemporaryFailureScenario(MockInsurerPolicy $policy, int $callCount, ?callable &$action): InsurerIssueResponse
    {
        $threshold = (int) config('mock_insurer.temporary_failure_attempts', 1);

        if ($callCount <= $threshold) {
            $action = function () use ($callCount, $threshold) {
                throw new InsurerTemporaryException(
                    "Mock insurer temporary 503 error on call {$callCount} of {$threshold}."
                );
            };

            return new InsurerIssueResponse(
                status: 'TEMPORARY_FAILURE',
                providerReference: null,
                policyNumber: null
            );
        }

        // Succeed on retry attempt
        return $this->handleSuccessScenario($policy);
    }

    /**
     * Scenario: TIMEOUT_AFTER_ISSUE - Insurer commits issuance, but HTTP client receives timeout.
     */
    protected function handleTimeoutAfterIssueScenario(MockInsurerPolicy $policy, ?callable &$action): InsurerIssueResponse
    {
        $providerRef = $policy->provider_reference ?? ('PRV-'.strtoupper(Str::random(10)));
        $policyNumber = $policy->policy_number ?? ('POL-'.strtoupper(Str::random(12)));

        $policy->update([
            'status' => 'ISSUED',
            'provider_reference' => $providerRef,
            'policy_number' => $policyNumber,
        ]);

        $action = function () {
            throw new InsurerTimeoutException(
                'Mock insurer simulated read timeout after policy was successfully issued.'
            );
        };

        return new InsurerIssueResponse(
            status: 'ISSUED',
            providerReference: $providerRef,
            policyNumber: $policyNumber,
            rawResponse: $policy->fresh()->toArray()
        );
    }

    /**
     * Scenario: DELAYED_CALLBACK - Return PENDING and queue a callback event.
     */
    protected function handleDelayedCallbackScenario(
        MockInsurerPolicy $policy,
        string $clientReference,
        int $deliveries = 1
    ): InsurerIssueResponse {
        $providerRef = $policy->provider_reference ?? ('PRV-'.strtoupper(Str::random(10)));
        $generatedPolicyNumber = 'POL-'.strtoupper(Str::random(12));

        $policy->update([
            'status' => 'PENDING',
            'provider_reference' => $providerRef,
        ]);

        $eventId = 'evt_'.(string) Str::uuid();
        $payload = [
            'event_id' => $eventId,
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $providerRef,
            'client_reference' => $clientReference,
            'policy_number' => $generatedPolicyNumber,
            'occurred_at' => now()->toIso8601String(),
        ];

        MockInsurerEvent::create([
            'event_id' => $eventId,
            'payload' => $payload,
            'deliveries_remaining' => $deliveries,
        ]);

        return new InsurerIssueResponse(
            status: 'PENDING',
            providerReference: $providerRef,
            policyNumber: null,
            rawResponse: $policy->fresh()->toArray()
        );
    }

    /**
     * Scenario: DUPLICATE_CALLBACK - Return PENDING and queue the identical event 3 times.
     */
    protected function handleDuplicateCallbackScenario(MockInsurerPolicy $policy, string $clientReference): InsurerIssueResponse
    {
        $deliveries = (int) config('mock_insurer.duplicate_callback_deliveries', 3);

        return $this->handleDelayedCallbackScenario($policy, $clientReference, deliveries: $deliveries);
    }

    /**
     * Scenario: PERMANENT_FAILURE - Non-retriable failure.
     */
    protected function handlePermanentFailureScenario(MockInsurerPolicy $policy, ?callable &$action): InsurerIssueResponse
    {
        $policy->update(['status' => 'REJECTED']);

        $action = function () {
            throw new InsurerPermanentException(
                'Policy issuance permanently rejected by mock insurer underwriting.'
            );
        };

        return new InsurerIssueResponse(
            status: 'REJECTED',
            providerReference: null,
            policyNumber: null,
            rawResponse: $policy->fresh()->toArray()
        );
    }
}
