<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProviderCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:128'],
            'event_type' => ['required', 'string', 'in:POLICY_ISSUED,POLICY_REJECTED'],
            'provider_reference' => ['nullable', 'string', 'max:128'],
            'client_reference' => ['nullable', 'string', 'max:128'],
            'policy_number' => ['required_if:event_type,POLICY_ISSUED', 'nullable', 'string', 'max:128'],
            'occurred_at' => ['required', 'string'],
        ];
    }
}
