<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\ApiException;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProviderCallbackService
{
    public function __construct(
        protected PolicyStateMachine $stateMachine,
    ) {}

    /**
     * Idempotently process an incoming provider callback event.
     *
     * Concurrency & Deduplication Strategy (Section 7.3):
     * - Table provider_events has a unique constraint on (provider, provider_event_id).
     * - In ONE atomic database transaction:
     *   1. Checks if the event_id already exists.
     *   2. If it exists, atomically increments received_count and returns 200 'duplicate'.
     *   3. Otherwise, locks the matching policy request row and applies the transition.
     *   4. Protects confirmed ISSUED states from being overwritten by late or conflicting callbacks.
     *   5. Commits the event record and state change together to prevent recorded-but-unapplied states.
     *   6. For rejected events, persists the audit record before raising client exceptions.
     *
     * @param  array<string, mixed>  $payload
     * @return array{result: string, event_id: string, request_status: string}
     */
    public function handle(array $payload, string $provider = 'mock'): array
    {
        $eventId = (string) $payload['event_id'];
        $eventType = (string) $payload['event_type'];
        $providerRef = ! empty($payload['provider_reference']) ? (string) $payload['provider_reference'] : null;
        $clientRef = ! empty($payload['client_reference']) ? (string) $payload['client_reference'] : null;
        $policyNumber = ! empty($payload['policy_number']) ? (string) $payload['policy_number'] : null;

        $postCommitAction = null;

        $response = DB::transaction(function () use (
            $payload,
            $provider,
            $eventId,
            $eventType,
            $providerRef,
            $clientRef,
            $policyNumber,
            &$postCommitAction
        ) {
            // Check for existing delivery of this event
            $existingEvent = ProviderEvent::where('provider', $provider)
                ->where('provider_event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existingEvent) {
                // Atomic increment of received_count for duplicate delivery
                $existingEvent->increment('received_count');

                $policy = $existingEvent->policy_request_id
                    ? PolicyRequest::find($existingEvent->policy_request_id)
                    : null;

                return [
                    'result' => 'duplicate',
                    'event_id' => $eventId,
                    'request_status' => $policy ? $policy->status->value : 'UNKNOWN',
                ];
            }

            // Resolve the policy request by provider_reference first, falling back to client_reference
            $policy = null;
            if ($providerRef !== null) {
                $policy = PolicyRequest::where('provider_reference', $providerRef)->first();
            }
            if (! $policy && $clientRef !== null) {
                $policy = PolicyRequest::where('id', $clientRef)->first();
            }

            if (! $policy) {
                throw new ApiException(
                    message: "No matching policy request found for provider_reference '{$providerRef}' or client_reference '{$clientRef}'.",
                    errorCode: 'REQUEST_NOT_FOUND',
                    statusCode: 404
                );
            }

            // Lock policy request row
            $current = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();

            // Case 1: Request is already in terminal ISSUED state
            if ($current->status === PolicyStatus::ISSUED) {
                $resultType = 'IGNORED_ALREADY_ISSUED';

                // Check for policy number anomaly (incoming policy_number differs from confirmed one)
                if ($policyNumber !== null && $policyNumber !== $current->policy_number) {
                    $resultType = 'CONFLICT_IGNORED';
                    Log::warning('Provider callback anomaly: received conflicting policy_number for already ISSUED request', [
                        'request_id' => $current->id,
                        'stored_policy_number' => $current->policy_number,
                        'incoming_policy_number' => $policyNumber,
                        'event_id' => $eventId,
                    ]);
                }

                $this->recordEvent($provider, $eventId, $current->id, $eventType, $payload, $resultType);

                return [
                    'result' => 'ignored',
                    'event_id' => $eventId,
                    'request_status' => 'ISSUED',
                ];
            }

            // Case 2: Request is in terminal FAILED state or un-submitted state
            if ($current->status === PolicyStatus::FAILED) {
                $this->recordEvent($provider, $eventId, $current->id, $eventType, $payload, 'REJECTED_INVALID_STATE');

                $postCommitAction = function () {
                    throw new ApiException(
                        message: 'Cannot apply provider callback to policy in terminal FAILED state.',
                        errorCode: 'INVALID_STATE_FOR_CALLBACK',
                        statusCode: 409
                    );
                };

                return [
                    'result' => 'rejected',
                    'event_id' => $eventId,
                    'request_status' => 'FAILED',
                ];
            }

            if ($current->status === PolicyStatus::RECEIVED || $current->status === PolicyStatus::VALIDATED) {
                $this->recordEvent($provider, $eventId, $current->id, $eventType, $payload, 'REJECTED_INVALID_STATE');

                $postCommitAction = function () {
                    throw new ApiException(
                        message: 'Cannot apply provider callback to policy request that has not been submitted.',
                        errorCode: 'REJECTED_INVALID_STATE',
                        statusCode: 409
                    );
                };

                return [
                    'result' => 'rejected',
                    'event_id' => $eventId,
                    'request_status' => $current->status->value,
                ];
            }

            // Case 3: Policy issuance confirmed
            if ($eventType === 'POLICY_ISSUED') {
                // Check if policy_number is already assigned to a DIFFERENT policy request
                if ($policyNumber !== null) {
                    $conflict = PolicyRequest::where('policy_number', $policyNumber)
                        ->where('id', '!=', $current->id)
                        ->exists();

                    if ($conflict) {
                        Log::error('Duplicate policy_number collision detected on provider callback', [
                            'request_id' => $current->id,
                            'policy_number' => $policyNumber,
                            'event_id' => $eventId,
                        ]);

                        $this->recordEvent($provider, $eventId, $current->id, $eventType, $payload, 'REJECTED_DUPLICATE_POLICY_NUMBER');

                        $postCommitAction = function () use ($policyNumber) {
                            throw new ApiException(
                                message: "Policy number '{$policyNumber}' is already registered to another policy.",
                                errorCode: 'DUPLICATE_POLICY_NUMBER',
                                statusCode: 409
                            );
                        };

                        return [
                            'result' => 'rejected',
                            'event_id' => $eventId,
                            'request_status' => $current->status->value,
                        ];
                    }
                }

                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::ISSUED,
                    reason: "Provider callback confirmed policy issuance (event {$eventId})",
                    source: 'CALLBACK',
                    context: [
                        'event_id' => $eventId,
                        'provider_reference' => $providerRef,
                        'policy_number' => $policyNumber,
                    ],
                    additionalAttributes: [
                        'provider_reference' => $providerRef ?? $current->provider_reference,
                        'policy_number' => $policyNumber,
                    ]
                );

                $this->recordEvent($provider, $eventId, $current->id, $eventType, $payload, 'APPLIED');

                return [
                    'result' => 'applied',
                    'event_id' => $eventId,
                    'request_status' => 'ISSUED',
                ];
            }

            // Case 4: Policy rejected by provider
            if ($eventType === 'POLICY_REJECTED') {
                $current = $this->stateMachine->transition(
                    request: $current,
                    to: PolicyStatus::FAILED,
                    reason: "Provider callback notified policy rejection (event {$eventId})",
                    source: 'CALLBACK',
                    context: ['event_id' => $eventId]
                );

                $this->recordEvent($provider, $eventId, $current->id, $eventType, $payload, 'APPLIED');

                return [
                    'result' => 'applied',
                    'event_id' => $eventId,
                    'request_status' => 'FAILED',
                ];
            }

            throw new ApiException(
                message: "Unsupported event_type '{$eventType}'.",
                errorCode: 'UNSUPPORTED_EVENT_TYPE',
                statusCode: 422
            );
        });

        // Execute post-commit action if specified (e.g. throwing 409 after audit event is committed)
        if (is_callable($postCommitAction)) {
            $postCommitAction();
        }

        return $response;
    }

    /**
     * Record the provider event entry in provider_events table.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function recordEvent(
        string $provider,
        string $eventId,
        ?string $policyRequestId,
        string $eventType,
        array $payload,
        string $result
    ): ProviderEvent {
        return ProviderEvent::create([
            'provider' => $provider,
            'provider_event_id' => $eventId,
            'policy_request_id' => $policyRequestId,
            'event_type' => $eventType,
            'payload' => $payload,
            'received_count' => 1,
            'processing_result' => $result,
            'processed_at' => now(),
        ]);
    }
}
