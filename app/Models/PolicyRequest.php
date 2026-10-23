<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PolicyStatus;
use App\Exceptions\ImmutableFieldException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'policy_requests';

    protected $fillable = [
        'id',
        'agent_id',
        'client_request_id',
        'request_fingerprint',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'travel_days',
        'premium_minor',
        'premium_currency',
        'premium_breakdown',
        'status',
        'provider_reference',
        'policy_number',
        'attempt_count',
        'last_response',
        'last_error_code',
        'last_error_message',
        'mock_scenario',
        'submitted_at',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PolicyStatus::class,
            'premium_breakdown' => 'array',
            'last_response' => 'array',
            'date_of_birth' => 'date:Y-m-d',
            'trip_start' => 'date:Y-m-d',
            'trip_end' => 'date:Y-m-d',
            'sum_insured' => 'integer',
            'travel_days' => 'integer',
            'premium_minor' => 'integer',
            'attempt_count' => 'integer',
            'submitted_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * Immutable fields that can never be modified after intake snapshot is persisted.
     *
     * @var array<string>
     */
    protected static array $immutableFields = [
        'client_request_id',
        'agent_id',
        'request_fingerprint',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'travel_days',
        'premium_minor',
        'premium_currency',
        'premium_breakdown',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (self::$immutableFields as $field) {
                if ($model->isDirty($field)) {
                    throw new ImmutableFieldException(
                        "Field '{$field}' is part of the immutable policy snapshot and cannot be modified after intake."
                    );
                }
            }
        });
    }

    /**
     * Issuance attempts for this policy request.
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class, 'policy_request_id')->orderBy('id', 'asc');
    }

    /**
     * State transition audit history.
     */
    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class, 'policy_request_id')->orderBy('id', 'asc');
    }

    /**
     * Inbound provider events for this policy request.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class, 'policy_request_id')->orderBy('id', 'asc');
    }

    /**
     * Helper to get formatted premium string (e.g. "225.00").
     */
    public function getFormattedPremiumAttribute(): string
    {
        $rupees = intdiv($this->premium_minor, 100);
        $paise = $this->premium_minor % 100;

        return sprintf('%d.%02d', $rupees, $paise);
    }
}
