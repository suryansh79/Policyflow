<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\InsurerIssueResponse;
use App\DTO\InsurerLookupResponse;

interface InsurerGateway
{
    /**
     * Submit a policy issuance request to the insurer.
     *
     * @param  string  $clientReference  The unique PolicyFlow policy request UUID
     * @param  array<string, mixed>  $payload  The policy issuance attributes
     * @param  string|null  $scenario  Optional mock scenario override
     */
    public function issue(string $clientReference, array $payload, ?string $scenario = null): InsurerIssueResponse;

    /**
     * Look up policy status at the insurer keyed by OUR client reference UUID.
     * Essential for resolving timeouts when provider_reference was never received.
     *
     * @param  string  $clientReference  The unique PolicyFlow policy request UUID
     */
    public function lookup(string $clientReference): InsurerLookupResponse;
}
