<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PolicyStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPolicyRequestsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(PolicyStatus::class)],
            'agent_id' => ['sometimes', 'string', 'max:64'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Get custom error messages for validation failures.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $allowedStatuses = implode(', ', array_column(PolicyStatus::cases(), 'value'));

        return [
            'status.Illuminate\Validation\Rules\Enum' => "The selected status is invalid. Supported statuses are: {$allowedStatuses}.",
            'per_page.min' => 'per_page must be at least 1.',
            'per_page.max' => 'per_page may not be greater than 100.',
            'per_page.integer' => 'per_page must be an integer.',
            'page.min' => 'page must be at least 1.',
            'page.integer' => 'page must be an integer.',
        ];
    }
}
