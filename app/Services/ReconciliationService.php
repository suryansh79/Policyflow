<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\InsurerGateway;
use App\Enums\PolicyStatus;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Exception;
use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function __construct(
        protected InsurerGateway $gateway,
        protected PolicyStateMachine $stateMachine,
    ) {}

    /**
     * Reconcile a policy request whose outcome is indeterminate (e.g. after a timeout or expired lease).
     *
     * In accordance with Section 7.2:
     * - Status lookup is performed OUTSIDE of any database transaction.
     * - Re-locks the row in a short transaction and only transitions if still reconcilable.
     * - Never blindly resubmits.
     *
     * @return array{request: PolicyRequest, outcome: string, provider_called: bool}
     */
    public function reconcile(PolicyRequest $policyRequest): array
    {
        // Terminal states cannot be reconciled
        if ($policyRequest->status->isTerminal()) {
            return [
                'request' => $policyRequest,
                'outcome' => $policyRequest->status === PolicyStatus::ISSUED ? 'ALREADY_ISSUED' : 'REQUEST_FAILED',
                'provider_called' => false,
            ];
        }

        $startTime = microtime(true);
        $lookupException = null;
        $lookupResponse = null;

        // Step 1: Call provider lookup outside of any transaction
        try {
            $lookupResponse = $this->gateway->lookup($policyRequest->id);
        } catch (Exception $e) {
            $lookupException = $e;
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        // Step 2: Record attempt and update state in a short transaction
        return DB::transaction(function () use ($policyRequest, $lookupResponse, $lookupException, $durationMs) {
            $current = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();

            // If another process already made it terminal in the interim, do not alter
            if ($current->status->isTerminal()) {
                return [
                    'request' => $current,
                    'outcome' => $current->status === PolicyStatus::ISSUED ? 'ALREADY_ISSUED' : 'REQUEST_FAILED',
                    'provider_called' => false,
                ];
            }

            // Calculate attempt sequence for RECONCILE attempts
            $attemptSeq = IssuanceAttempt::where('policy_request_id', $current->id)
                ->where('type', 'RECONCILE')
                ->count() + 1;

            if ($lookupException !== null) {
                // Lookup failed; stay in NEEDS_RECONCILIATION
                IssuanceAttempt::create([
                    'policy_request_id' => $current->id,
                    'type' => 'RECONCILE',
                    'sequence' => $attemptSeq,
                    'outcome' => 'LOOKUP_FAILED',
                    'error_code' => 'RECONCILIATION_LOOKUP_ERROR',
                    'error_message' => $lookupException->getMessage(),
                    'started_at' => now(),
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                ]);

                if ($current->status !== PolicyStatus::NEEDS_RECONCILIATION) {
                    $current = $this->stateMachine->transition(
                        request: $current,
                        to: PolicyStatus::NEEDS_RECONCILIATION,
                        reason: 'Reconciliation lookup error: '.$lookupException->getMessage(),
                        source: 'RECONCILE',
                        context: ['error' => $lookupException->getMessage()]
                    );
                }

                return [
                    'request' => $current,
                    'outcome' => 'RECONCILIATION_FAILED',
                    'provider_called' => true,
                ];
            }

            // Evaluate provider lookup response
            if ($lookupResponse->isIssued()) {
                // Provider confirmed issued -> transition to ISSUED with provider reference and policy number
                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::ISSUED,
                    reason: 'Provider status lookup confirmed policy was issued',
                    source: 'RECONCILE',
                    context: [
                        'policy_number' => $lookupResponse->policyNumber,
                        'provider_reference' => $lookupResponse->providerReference,
                    ],
                    additionalAttributes: [
                        'provider_reference' => $lookupResponse->providerReference,
                        'policy_number' => $lookupResponse->policyNumber,
                        'last_response' => $lookupResponse->rawResponse,
                    ]
                );

                IssuanceAttempt::create([
                    'policy_request_id' => $current->id,
                    'type' => 'RECONCILE',
                    'sequence' => $attemptSeq,
                    'outcome' => 'ISSUED',
                    'response' => $lookupResponse->rawResponse,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                ]);

                return [
                    'request' => $current,
                    'outcome' => 'ISSUED',
                    'provider_called' => true,
                ];
            }

            if ($lookupResponse->isPending()) {
                if ($current->status !== PolicyStatus::PENDING_CONFIRMATION) {
                    $current = $this->stateMachine->transition(
                        request: $current,
                        to: PolicyStatus::PENDING_CONFIRMATION,
                        reason: 'Provider status lookup confirmed policy is pending confirmation',
                        source: 'RECONCILE',
                        context: ['status' => 'PENDING'],
                        additionalAttributes: [
                            'provider_reference' => $lookupResponse->providerReference,
                            'last_response' => $lookupResponse->rawResponse,
                        ]
                    );
                }

                IssuanceAttempt::create([
                    'policy_request_id' => $current->id,
                    'type' => 'RECONCILE',
                    'sequence' => $attemptSeq,
                    'outcome' => 'PENDING_CONFIRMATION',
                    'response' => $lookupResponse->rawResponse,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                ]);

                return [
                    'request' => $current,
                    'outcome' => 'AWAITING_CONFIRMATION',
                    'provider_called' => true,
                ];
            }

            if ($lookupResponse->isNotFound()) {
                // Provider confirms it has NO record -> safe to retry! Transition to RETRY_PENDING
                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::RETRY_PENDING,
                    reason: 'Provider confirmed no policy record exists; safe to retry',
                    source: 'RECONCILE',
                    context: ['result' => 'NOT_FOUND']
                );

                IssuanceAttempt::create([
                    'policy_request_id' => $current->id,
                    'type' => 'RECONCILE',
                    'sequence' => $attemptSeq,
                    'outcome' => 'RETRY_PENDING',
                    'response' => $lookupResponse->rawResponse,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                ]);

                return [
                    'request' => $current,
                    'outcome' => 'RETRY_PENDING',
                    'provider_called' => true,
                ];
            }

            // Default fallback if provider status is unrecognized
            IssuanceAttempt::create([
                'policy_request_id' => $current->id,
                'type' => 'RECONCILE',
                'sequence' => $attemptSeq,
                'outcome' => 'UNRESOLVED',
                'response' => $lookupResponse->rawResponse,
                'started_at' => now(),
                'finished_at' => now(),
                'duration_ms' => $durationMs,
            ]);

            return [
                'request' => $current,
                'outcome' => 'NEEDS_RECONCILIATION',
                'provider_called' => true,
            ];
        });
    }
}
