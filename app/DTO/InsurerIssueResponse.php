<?php

declare(strict_types=1);

namespace App\DTO;

readonly class InsurerIssueResponse
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $status, // 'ISSUED', 'PENDING', 'REJECTED'
        public ?string $providerReference,
        public ?string $policyNumber,
        public array $rawResponse = [],
    ) {}

    public function isIssued(): bool
    {
        return $this->status === 'ISSUED';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isRejected(): bool
    {
        return $this->status === 'REJECTED';
    }
}
