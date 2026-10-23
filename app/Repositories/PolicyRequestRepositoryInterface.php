<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PolicyRequest;

interface PolicyRequestRepositoryInterface
{
    /**
     * Find an existing policy request by agent ID and client request ID.
     */
    public function findByAgentAndClientRequestId(string $agentId, string $clientRequestId): ?PolicyRequest;

    /**
     * Find a policy request by its UUID primary key.
     */
    public function find(string $id): ?PolicyRequest;

    /**
     * Persist a new policy request with its initial state history in a single transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createWithInitialHistory(array $attributes): PolicyRequest;
}
