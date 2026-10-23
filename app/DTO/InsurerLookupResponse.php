<?php

declare(strict_types=1);

namespace App\DTO;

readonly class InsurerLookupResponse
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public bool $found,
        public ?string $status, // 'ISSUED', 'PENDING', 'REJECTED', 'NOT_FOUND'
        public ?string $providerReference,
        public ?string $policyNumber,
        public array $rawResponse = [],
    ) {}

    public function isIssued(): bool
    {
        return $this->found && $this->status === 'ISSUED';
    }

    public function isPending(): bool
    {
        return $this->found && $this->status === 'PENDING';
    }

    public function isNotFound(): bool
    {
        return ! $this->found || $this->status === 'NOT_FOUND';
    }
}
