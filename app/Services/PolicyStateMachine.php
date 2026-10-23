<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\LostStateRaceException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;

class PolicyStateMachine
{
    /**
     * Transition a policy request to a new status.
     *
     * Concurrency and integrity guarantees:
     * 1. Validates the transition against the strict state machine matrix.
     * 2. Executes inside a database transaction with row-level pessimistic locking (lockForUpdate)
     *    where supported by the database engine (MySQL/PostgreSQL).
     * 3. Performs an atomic Compare-And-Swap (CAS) update (WHERE id = :id AND status = :from).
     * 4. Treats 0 affected rows as a lost concurrent race and aborts without corrupting state.
     * 5. Persists the transition in the append-only policy_state_history audit table in the same transaction.
     *
     * @param  PolicyRequest|string  $request  The policy request model or its UUID
     * @param  PolicyStatus  $to  Target status
     * @param  string  $reason  Audit explanation
     * @param  string  $source  Transition trigger (API, SUBMIT, CALLBACK, RECONCILE, SYSTEM)
     * @param  array<string, mixed>  $context  Optional audit metadata
     * @param  array<string, mixed>  $additionalAttributes  Optional attributes to persist on policy_requests in same update
     *
     * @throws InvalidStateTransitionException
     * @throws LostStateRaceException
     */
    public function transition(
        PolicyRequest|string $request,
        PolicyStatus $to,
        string $reason,
        string $source,
        array $context = [],
        array $additionalAttributes = []
    ): PolicyRequest {
        $requestId = $request instanceof PolicyRequest ? $request->id : $request;
        $expectedFrom = $request instanceof PolicyRequest ? $request->status : null;

        return DB::transaction(function () use ($requestId, $expectedFrom, $to, $reason, $source, $context, $additionalAttributes) {
            // Lock row for update
            $current = PolicyRequest::where('id', $requestId)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $current->status;

            // If caller passed a model with a stale status, treat as race
            if ($expectedFrom !== null && $expectedFrom !== $from) {
                if ($from->isTerminal()) {
                    throw new InvalidStateTransitionException(
                        from: $from,
                        to: $to,
                        message: "Cannot transition from terminal status '{$from->value}' to '{$to->value}'."
                    );
                }

                throw new LostStateRaceException(
                    "Stale model race: caller expected status '{$expectedFrom->value}', but row is currently in '{$from->value}'."
                );
            }

            // Verify transition against enum rules
            if (! $from->canTransitionTo($to)) {
                throw new InvalidStateTransitionException(
                    from: $from,
                    to: $to,
                    message: "Invalid state transition from '{$from->value}' to '{$to->value}'."
                );
            }

            // Prepare update payload
            $updateData = array_merge($additionalAttributes, [
                'status' => $to->value,
                'updated_at' => now(),
            ]);

            if ($to === PolicyStatus::ISSUED && ! isset($updateData['issued_at'])) {
                $updateData['issued_at'] = now();
            }

            if ($to === PolicyStatus::SUBMITTED && ! isset($updateData['submitted_at'])) {
                $updateData['submitted_at'] = now();
            }

            // Atomic Compare-And-Swap update: status must still equal $from at update instant
            $affected = DB::table('policy_requests')
                ->where('id', $current->id)
                ->where('status', $from->value)
                ->update($updateData);

            if ($affected === 0) {
                throw new LostStateRaceException(
                    "Optimistic CAS update failed for policy request {$current->id}. Expected status '{$from->value}'."
                );
            }

            // Record audit trail row in same transaction
            PolicyStateHistory::create([
                'policy_request_id' => $current->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'source' => $source,
                'context' => ! empty($context) ? $context : null,
                'created_at' => now(),
            ]);

            return $current->fresh();
        });
    }
}
