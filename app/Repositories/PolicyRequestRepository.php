<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

class PolicyRequestRepository implements PolicyRequestRepositoryInterface
{
    public function findByAgentAndClientRequestId(string $agentId, string $clientRequestId): ?PolicyRequest
    {
        return PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();
    }

    public function find(string $id): ?PolicyRequest
    {
        return PolicyRequest::find($id);
    }

    /**
     * Persist a new policy request with its initial state history in a single transaction.
     * State history sequence: null -> RECEIVED -> VALIDATED.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createWithInitialHistory(array $attributes): PolicyRequest
    {
        return DB::transaction(function () use ($attributes) {
            $attributes['status'] = PolicyStatus::VALIDATED;

            $request = PolicyRequest::create($attributes);

            // Step 1 of history: null -> RECEIVED
            PolicyStateHistory::create([
                'policy_request_id' => $request->id,
                'from_status' => null,
                'to_status' => PolicyStatus::RECEIVED,
                'reason' => 'Policy purchase request received via API intake',
                'source' => 'API',
                'context' => [
                    'agent_id' => $request->agent_id,
                    'client_request_id' => $request->client_request_id,
                ],
            ]);

            // Step 2 of history: RECEIVED -> VALIDATED
            PolicyStateHistory::create([
                'policy_request_id' => $request->id,
                'from_status' => PolicyStatus::RECEIVED,
                'to_status' => PolicyStatus::VALIDATED,
                'reason' => 'Contract validation and deterministic pricing snapshot verified',
                'source' => 'SYSTEM',
                'context' => [
                    'premium_minor' => $request->premium_minor,
                    'travel_days' => $request->travel_days,
                ],
            ]);

            return $request;
        });
    }
}
