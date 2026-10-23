<?php

declare(strict_types=1);

namespace App\Mock\Insurer;

use App\Contracts\InsurerGateway;
use App\DTO\InsurerIssueResponse;
use App\DTO\InsurerLookupResponse;

class MockInsurerGateway implements InsurerGateway
{
    public function __construct(
        protected MockInsurerService $service
    ) {}

    public function issue(string $clientReference, array $payload, ?string $scenario = null): InsurerIssueResponse
    {
        return $this->service->issue($clientReference, $payload, $scenario);
    }

    public function lookup(string $clientReference): InsurerLookupResponse
    {
        return $this->service->lookup($clientReference);
    }
}
