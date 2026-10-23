<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceAttempt extends Model
{
    use HasFactory;

    protected $table = 'issuance_attempts';

    protected $fillable = [
        'policy_request_id',
        'type',
        'sequence',
        'outcome',
        'http_status',
        'error_code',
        'error_message',
        'response',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'response' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class, 'policy_request_id');
    }
}
