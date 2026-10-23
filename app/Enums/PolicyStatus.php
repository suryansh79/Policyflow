<?php

declare(strict_types=1);

namespace App\Enums;

enum PolicyStatus: string
{
    case RECEIVED = 'RECEIVED';
    case VALIDATED = 'VALIDATED';
    case SUBMITTED = 'SUBMITTED';
    case PENDING_CONFIRMATION = 'PENDING_CONFIRMATION';
    case RETRY_PENDING = 'RETRY_PENDING';
    case NEEDS_RECONCILIATION = 'NEEDS_RECONCILIATION';
    case ISSUED = 'ISSUED';
    case FAILED = 'FAILED';

    /**
     * Determine whether the status is terminal (cannot transition further).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::ISSUED, self::FAILED => true,
            default => false,
        };
    }

    /**
     * Get all allowed direct target statuses from this status.
     *
     * @return array<PolicyStatus>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::RECEIVED => [
                self::VALIDATED,
                self::FAILED,
            ],
            self::VALIDATED => [
                self::SUBMITTED,
                self::FAILED,
            ],
            self::SUBMITTED => [
                self::ISSUED,
                self::PENDING_CONFIRMATION,
                self::RETRY_PENDING,
                self::NEEDS_RECONCILIATION,
                self::FAILED,
            ],
            self::RETRY_PENDING => [
                self::SUBMITTED,
                self::FAILED,
            ],
            self::PENDING_CONFIRMATION => [
                self::ISSUED,
                self::FAILED,
                self::NEEDS_RECONCILIATION,
            ],
            self::NEEDS_RECONCILIATION => [
                self::ISSUED,
                self::FAILED,
                self::PENDING_CONFIRMATION,
                self::RETRY_PENDING,
            ],
            self::ISSUED, self::FAILED => [],
        };
    }

    /**
     * Check if transitioning to the target status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }
}
