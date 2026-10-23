<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\InsurerGateway;
use App\Enums\PolicyStatus;
use App\Exceptions\ApiException;
use App\Exceptions\InsurerPermanentException;
use App\Exceptions\InsurerTemporaryException;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use Exception;
use Illuminate\Support\Facades\DB;

class SubmissionService
{
    public function __construct(
        protected InsurerGateway $gateway,
        protected PolicyStateMachine $stateMachine,
        protected ReconciliationService $reconciliationService,
    ) {}

    /**
     * Submit or safely resume policy issuance.
     *
     * In accordance with Section 7.2:
     * - Never holds a DB transaction or lock open across the provider HTTP call.
     * - Uses atomic Compare-And-Swap claim to ensure exactly one worker processes the request.
     * - Expired submission lease is treated as crashed worker and reconciled.
     * - In-flight callbacks arriving before provider response are protected from late overwrites.
     *
     * @return array{request: PolicyRequest, outcome: string, provider_called: bool}
     */
    public function submit(string $id): array
    {
        $policyRequest = PolicyRequest::findOrFail($id);

        // Case 1: Terminal ISSUED state -> safe no-op
        if ($policyRequest->status === PolicyStatus::ISSUED) {
            return [
                'request' => $policyRequest,
                'outcome' => 'ALREADY_ISSUED',
                'provider_called' => false,
            ];
        }

        // Case 2: Terminal FAILED state -> 409 Conflict
        if ($policyRequest->status === PolicyStatus::FAILED) {
            throw new ApiException(
                message: "Policy request {$id} is in terminal FAILED state and cannot be submitted.",
                errorCode: 'REQUEST_NOT_SUBMITTABLE',
                statusCode: 409
            );
        }

        // Case 3: PENDING_CONFIRMATION -> awaiting asynchronous callback event
        if ($policyRequest->status === PolicyStatus::PENDING_CONFIRMATION) {
            return [
                'request' => $policyRequest,
                'outcome' => 'AWAITING_CONFIRMATION',
                'provider_called' => false,
            ];
        }

        // Case 4: NEEDS_RECONCILIATION -> status lookup only, never re-issue blindly
        if ($policyRequest->status === PolicyStatus::NEEDS_RECONCILIATION) {
            return $this->reconciliationService->reconcile($policyRequest);
        }

        // Case 5: Already SUBMITTED -> check submission lease
        if ($policyRequest->status === PolicyStatus::SUBMITTED) {
            $leaseSeconds = (int) config('policyflow.submission_lease_seconds', 120);
            $submittedAt = $policyRequest->submitted_at ?? $policyRequest->updated_at;
            $isLeaseExpired = $submittedAt ? $submittedAt->diffInSeconds(now()) >= $leaseSeconds : false;

            if (! $isLeaseExpired) {
                // Lease is still fresh -> concurrent or repeated request
                return [
                    'request' => $policyRequest,
                    'outcome' => 'IN_PROGRESS',
                    'provider_called' => false,
                ];
            }

            // Lease expired (crashed worker) -> transition to NEEDS_RECONCILIATION and reconcile
            $policyRequest = $this->stateMachine->transition(
                request: $policyRequest,
                to: PolicyStatus::NEEDS_RECONCILIATION,
                reason: "Submission lease of {$leaseSeconds}s expired; crashed worker assumed",
                source: 'SYSTEM',
                context: ['lease_seconds' => $leaseSeconds]
            );

            return $this->reconciliationService->reconcile($policyRequest);
        }

        // Case 6: VALIDATED or RETRY_PENDING -> Claim and attempt issuance
        return $this->executeClaimAndSubmit($policyRequest);
    }

    /**
     * Executes the 3-step claim, external call, and result recording algorithm.
     *
     * @return array{request: PolicyRequest, outcome: string, provider_called: bool}
     */
    protected function executeClaimAndSubmit(PolicyRequest $policyRequest): array
    {
        $claimed = false;
        $attemptCount = 0;

        // Step 1: Claim (short transaction)
        $claimedRequest = DB::transaction(function () use ($policyRequest, &$claimed, &$attemptCount) {
            $current = PolicyRequest::where('id', $policyRequest->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== PolicyStatus::VALIDATED && $current->status !== PolicyStatus::RETRY_PENDING) {
                return $current; // Lost claim race
            }

            $attemptCount = $current->attempt_count + 1;

            $updated = $this->stateMachine->transition(
                request: $current,
                to: PolicyStatus::SUBMITTED,
                reason: "Claimed for issuance attempt {$attemptCount}",
                source: 'SUBMIT',
                context: ['attempt' => $attemptCount],
                additionalAttributes: [
                    'attempt_count' => $attemptCount,
                    'submitted_at' => now(),
                ]
            );

            // Create initial attempt record
            IssuanceAttempt::create([
                'policy_request_id' => $updated->id,
                'type' => 'ISSUE',
                'sequence' => $attemptCount,
                'outcome' => 'STARTED',
                'started_at' => now(),
            ]);

            $claimed = true;

            return $updated;
        });

        if (! $claimed) {
            return [
                'request' => $claimedRequest,
                'outcome' => 'IN_PROGRESS',
                'provider_called' => false,
            ];
        }

        // Step 2: Call provider OUTSIDE of any transaction
        $startTime = microtime(true);
        $providerResponse = null;
        $providerException = null;

        try {
            $providerResponse = $this->gateway->issue(
                clientReference: $claimedRequest->id,
                payload: [
                    'plan_code' => $claimedRequest->plan_code,
                    'sum_insured' => $claimedRequest->sum_insured,
                    'destination' => $claimedRequest->destination,
                    'premium_minor' => $claimedRequest->premium_minor,
                ],
                scenario: $claimedRequest->mock_scenario
            );
        } catch (Exception $e) {
            $providerException = $e;
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        // Step 3: Record result (short transaction)
        return DB::transaction(function () use (
            $claimedRequest,
            $attemptCount,
            $providerResponse,
            $providerException,
            $durationMs
        ) {
            $current = PolicyRequest::where('id', $claimedRequest->id)->lockForUpdate()->firstOrFail();

            // Find the attempt row to update
            $attempt = IssuanceAttempt::where('policy_request_id', $current->id)
                ->where('type', 'ISSUE')
                ->where('sequence', $attemptCount)
                ->first();

            // Guard: If an asynchronous callback already moved request to ISSUED while call was in-flight,
            // record late provider response on attempt without altering or corrupting request.
            if ($current->status === PolicyStatus::ISSUED) {
                if ($attempt) {
                    $attempt->update([
                        'finished_at' => now(),
                        'duration_ms' => $durationMs,
                        'outcome' => 'LATE_IGNORED',
                        'response' => $providerResponse?->rawResponse,
                    ]);
                }

                return [
                    'request' => $current,
                    'outcome' => 'ALREADY_ISSUED',
                    'provider_called' => true,
                ];
            }

            // Case A: Provider returned successful issuance
            if ($providerResponse && $providerResponse->isIssued()) {
                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::ISSUED,
                    reason: 'Provider successfully issued policy',
                    source: 'SUBMIT',
                    context: [
                        'policy_number' => $providerResponse->policyNumber,
                        'provider_reference' => $providerResponse->providerReference,
                    ],
                    additionalAttributes: [
                        'provider_reference' => $providerResponse->providerReference,
                        'policy_number' => $providerResponse->policyNumber,
                        'last_response' => $providerResponse->rawResponse,
                    ]
                );

                if ($attempt) {
                    $attempt->update([
                        'finished_at' => now(),
                        'duration_ms' => $durationMs,
                        'outcome' => 'ISSUED',
                        'response' => $providerResponse->rawResponse,
                    ]);
                }

                return [
                    'request' => $current,
                    'outcome' => 'ISSUED',
                    'provider_called' => true,
                ];
            }

            // Case B: Provider accepted request, awaiting callback (DELAYED_CALLBACK)
            if ($providerResponse && $providerResponse->isPending()) {
                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::PENDING_CONFIRMATION,
                    reason: 'Provider accepted issuance; awaiting asynchronous callback confirmation',
                    source: 'SUBMIT',
                    context: ['provider_reference' => $providerResponse->providerReference],
                    additionalAttributes: [
                        'provider_reference' => $providerResponse->providerReference,
                        'last_response' => $providerResponse->rawResponse,
                    ]
                );

                if ($attempt) {
                    $attempt->update([
                        'finished_at' => now(),
                        'duration_ms' => $durationMs,
                        'outcome' => 'PENDING_CONFIRMATION',
                        'response' => $providerResponse->rawResponse,
                    ]);
                }

                return [
                    'request' => $current,
                    'outcome' => 'AWAITING_CONFIRMATION',
                    'provider_called' => true,
                ];
            }

            // Case C: Temporary failure -> check max retry attempts
            if ($providerException instanceof InsurerTemporaryException) {
                $maxAttempts = (int) config('policyflow.max_submission_attempts', 3);

                if ($attemptCount >= $maxAttempts) {
                    $current = $this->stateMachine->transition(
                        request: $current,
                        to: PolicyStatus::FAILED,
                        reason: "Retries exhausted after {$attemptCount} attempts. Last error: ".$providerException->getMessage(),
                        source: 'SYSTEM',
                        context: ['error' => $providerException->getMessage()],
                        additionalAttributes: [
                            'last_error_code' => 'RETRIES_EXHAUSTED',
                            'last_error_message' => mb_substr($providerException->getMessage(), 0, 500),
                        ]
                    );

                    if ($attempt) {
                        $attempt->update([
                            'finished_at' => now(),
                            'duration_ms' => $durationMs,
                            'outcome' => 'RETRIES_EXHAUSTED',
                            'error_code' => 'RETRIES_EXHAUSTED',
                            'error_message' => $providerException->getMessage(),
                        ]);
                    }

                    return [
                        'request' => $current,
                        'outcome' => 'FAILED',
                        'provider_called' => true,
                    ];
                }

                // Move to RETRY_PENDING
                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::RETRY_PENDING,
                    reason: "Temporary failure on attempt {$attemptCount}; safe to retry",
                    source: 'SUBMIT',
                    context: ['error' => $providerException->getMessage()],
                    additionalAttributes: [
                        'last_error_code' => $providerException->getErrorCode(),
                        'last_error_message' => mb_substr($providerException->getMessage(), 0, 500),
                    ]
                );

                if ($attempt) {
                    $attempt->update([
                        'finished_at' => now(),
                        'duration_ms' => $durationMs,
                        'outcome' => 'RETRY_PENDING',
                        'error_code' => $providerException->getErrorCode(),
                        'error_message' => $providerException->getMessage(),
                    ]);
                }

                return [
                    'request' => $current,
                    'outcome' => 'RETRY_PENDING',
                    'provider_called' => true,
                ];
            }

            // Case D: Permanent rejection by insurer
            if ($providerException instanceof InsurerPermanentException) {
                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::FAILED,
                    reason: 'Insurer permanently rejected issuance: '.$providerException->getMessage(),
                    source: 'SUBMIT',
                    context: ['error' => $providerException->getMessage()],
                    additionalAttributes: [
                        'last_error_code' => $providerException->getErrorCode(),
                        'last_error_message' => mb_substr($providerException->getMessage(), 0, 500),
                    ]
                );

                if ($attempt) {
                    $attempt->update([
                        'finished_at' => now(),
                        'duration_ms' => $durationMs,
                        'outcome' => 'FAILED',
                        'error_code' => $providerException->getErrorCode(),
                        'error_message' => $providerException->getMessage(),
                    ]);
                }

                return [
                    'request' => $current,
                    'outcome' => 'FAILED',
                    'provider_called' => true,
                ];
            }

            // Case E: Timeout or unknown failure -> transition to NEEDS_RECONCILIATION
            $current = $this->stateMachine->transition(
                request: $current,
                to: PolicyStatus::NEEDS_RECONCILIATION,
                reason: 'Insurer call timed out or threw unknown error; outcome indeterminate',
                source: 'SUBMIT',
                context: ['error' => $providerException?->getMessage() ?? 'Unknown error'],
                additionalAttributes: [
                    'last_error_code' => 'INSURER_TIMEOUT',
                    'last_error_message' => mb_substr($providerException?->getMessage() ?? 'Timeout', 0, 500),
                ]
            );

            if ($attempt) {
                $attempt->update([
                    'finished_at' => now(),
                    'duration_ms' => $durationMs,
                    'outcome' => 'NEEDS_RECONCILIATION',
                    'error_code' => 'INSURER_TIMEOUT',
                    'error_message' => $providerException?->getMessage(),
                ]);
            }

            // Inline reconciliation if enabled
            if (config('policyflow.reconcile_inline_on_timeout', true)) {
                return $this->reconciliationService->reconcile($current);
            }

            return [
                'request' => $current,
                'outcome' => 'NEEDS_RECONCILIATION',
                'provider_called' => true,
            ];
        });
    }
}
