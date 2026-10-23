<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Pricing\PremiumCalculator;
use App\Domain\Pricing\PricingInput;
use App\Exceptions\ApiException;
use App\Models\PolicyRequest;
use App\Repositories\PolicyRequestRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class PolicyRequestIntakeService
{
    public function __construct(
        protected PolicyRequestRepositoryInterface $repository,
        protected PremiumCalculator $calculator,
    ) {}

    /**
     * Intake and process a policy purchase request with atomic idempotency and concurrency protection.
     *
     * @param  array<string, mixed>  $payload
     * @return array{request: PolicyRequest, is_replayed: bool}
     */
    public function handle(array $payload): array
    {
        $agentId = trim((string) $payload['agent_id']);
        $clientRequestId = trim((string) $payload['client_request_id']);

        // Generate canonical, type-normalized fingerprint of the 9 core business fields
        $fingerprint = self::generateFingerprint($payload);

        // Step 1: Fast-path lookup
        $existing = $this->repository->findByAgentAndClientRequestId($agentId, $clientRequestId);
        if ($existing) {
            if ($existing->request_fingerprint === $fingerprint) {
                Log::info('Policy request intake replayed existing record', [
                    'request_id' => $existing->id,
                    'agent_id' => $agentId,
                    'client_request_id' => $clientRequestId,
                    'status' => $existing->status->value,
                ]);

                return [
                    'request' => $existing,
                    'is_replayed' => true,
                ];
            }

            throw new ApiException(
                message: "Idempotency key '{$clientRequestId}' was previously used with a different request payload.",
                errorCode: 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                statusCode: 409
            );
        }

        // Step 2: Deterministic pricing snapshot calculation
        $pricingInput = new PricingInput(
            planCode: (string) $payload['plan_code'],
            sumInsured: (int) $payload['sum_insured'],
            destination: (string) $payload['destination'],
            dateOfBirth: (string) $payload['date_of_birth'],
            tripStart: (string) $payload['trip_start'],
            tripEnd: (string) $payload['trip_end'],
        );

        $breakdown = $this->calculator->calculate($pricingInput);

        // Optional mock scenario flag handling (allowed in testing/local)
        $mockScenario = null;
        if (config('mock_insurer.mock_scenario_allowed') && ! empty($payload['mock_scenario'])) {
            $mockScenario = (string) $payload['mock_scenario'];
        }

        $attributes = [
            'agent_id' => $agentId,
            'client_request_id' => $clientRequestId,
            'request_fingerprint' => $fingerprint,
            'customer_name' => trim((string) $payload['customer_name']),
            'date_of_birth' => (string) $payload['date_of_birth'],
            'destination' => strtoupper(trim((string) $payload['destination'])),
            'trip_start' => (string) $payload['trip_start'],
            'trip_end' => (string) $payload['trip_end'],
            'plan_code' => strtoupper(trim((string) $payload['plan_code'])),
            'sum_insured' => (int) $payload['sum_insured'],
            'travel_days' => $breakdown->travelDays,
            'premium_minor' => $breakdown->minor,
            'premium_currency' => $breakdown->currency,
            'premium_breakdown' => $breakdown->toArray(),
            'mock_scenario' => $mockScenario,
        ];

        // Step 3: Insert with optimistic concurrency and unique constraint protection
        try {
            $newRequest = $this->repository->createWithInitialHistory($attributes);

            Log::info('Policy request intake created new record', [
                'request_id' => $newRequest->id,
                'agent_id' => $agentId,
                'client_request_id' => $clientRequestId,
                'status' => $newRequest->status->value,
            ]);

            return [
                'request' => $newRequest,
                'is_replayed' => false,
            ];
        } catch (UniqueConstraintViolationException|QueryException $e) {
            // Near-simultaneous race condition: another concurrent worker won the insert
            // Re-fetch the winning row and compare fingerprints
            $winner = $this->repository->findByAgentAndClientRequestId($agentId, $clientRequestId);
            if (! $winner) {
                // If not found, it wasn't an agent/client_request_id collision; rethrow
                throw $e;
            }

            if ($winner->request_fingerprint === $fingerprint) {
                Log::info('Policy request intake replayed winning concurrent record', [
                    'request_id' => $winner->id,
                    'agent_id' => $agentId,
                    'client_request_id' => $clientRequestId,
                    'status' => $winner->status->value,
                ]);

                return [
                    'request' => $winner,
                    'is_replayed' => true,
                ];
            }

            throw new ApiException(
                message: "Idempotency key '{$clientRequestId}' was previously used with a different request payload.",
                errorCode: 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD',
                statusCode: 409
            );
        }
    }

    /**
     * Compute a SHA-256 fingerprint from the canonical, type-normalized representation
     * of the nine required business fields.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function generateFingerprint(array $payload): string
    {
        $canonical = [
            'agent_id' => trim((string) $payload['agent_id']),
            'client_request_id' => trim((string) $payload['client_request_id']),
            'customer_name' => trim((string) $payload['customer_name']),
            'date_of_birth' => (string) $payload['date_of_birth'],
            'destination' => strtoupper(trim((string) $payload['destination'])),
            'plan_code' => strtoupper(trim((string) $payload['plan_code'])),
            'sum_insured' => (int) $payload['sum_insured'],
            'trip_end' => (string) $payload['trip_end'],
            'trip_start' => (string) $payload['trip_start'],
        ];

        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
