<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockInsurerPolicy extends Model
{
    use HasFactory;

    protected $table = 'mock_insurer_policies';

    protected $fillable = [
        'client_reference',
        'scenario',
        'issue_call_count',
        'status',
        'provider_reference',
        'policy_number',
    ];

    protected function casts(): array
    {
        return [
            'issue_call_count' => 'integer',
        ];
    }
}
