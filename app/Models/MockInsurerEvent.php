<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInsurerEvent extends Model
{
    use HasFactory;

    protected $table = 'mock_insurer_events';

    protected $fillable = [
        'event_id',
        'payload',
        'deliveries_remaining',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'deliveries_remaining' => 'integer',
        ];
    }
}
