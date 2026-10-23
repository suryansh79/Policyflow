<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PolicyStatus;
use App\Exceptions\ImmutableFieldException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyStateHistory extends Model
{
    use HasFactory;

    protected $table = 'policy_state_history';

    public $timestamps = false;

    protected $fillable = [
        'policy_request_id',
        'from_status',
        'to_status',
        'reason',
        'source',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => PolicyStatus::class,
            'to_status' => PolicyStatus::class,
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Enforce append-only integrity: rows cannot be modified or deleted once written
        static::updating(function (): void {
            throw new ImmutableFieldException('Policy state history is append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new ImmutableFieldException('Policy state history is append-only and cannot be deleted.');
        });
    }

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class, 'policy_request_id');
    }
}
