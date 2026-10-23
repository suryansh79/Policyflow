<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderEvent extends Model
{
    use HasFactory;

    protected $table = 'provider_events';

    protected $fillable = [
        'provider',
        'provider_event_id',
        'policy_request_id',
        'event_type',
        'payload',
        'received_count',
        'processing_result',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_count' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class, 'policy_request_id');
    }
}
