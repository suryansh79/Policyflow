<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePolicyRequestRequest extends FormRequest
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
            'client_request_id' => ['required', 'string', 'max:128'],
            'agent_id' => ['required', 'string', 'max:64'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:trip_start'],
            'destination' => ['required', 'string', 'in:DOMESTIC,INTERNATIONAL'],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', 'in:BASIC,PLUS,PREMIUM'],
            'sum_insured' => ['required', 'integer', 'in:100000,300000,500000'],
            'mock_scenario' => ['nullable', 'string', 'in:SUCCESS,TEMPORARY_FAILURE,TIMEOUT_AFTER_ISSUE,DELAYED_CALLBACK,DUPLICATE_CALLBACK,PERMANENT_FAILURE'],
        ];
    }

    /**
     * User-friendly custom messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan_code.in' => 'plan_code must be one of: BASIC, PLUS, PREMIUM.',
            'sum_insured.in' => 'sum_insured must be one of: 100000, 300000, 500000.',
            'destination.in' => 'destination must be one of: DOMESTIC, INTERNATIONAL.',
            'trip_end.after_or_equal' => 'trip_end must be on or after trip_start.',
            'date_of_birth.before_or_equal' => 'date_of_birth must be on or before trip_start.',
            'date_of_birth.date_format' => 'date_of_birth must be in Y-m-d format.',
            'trip_start.date_format' => 'trip_start must be in Y-m-d format.',
            'trip_end.date_format' => 'trip_end must be in Y-m-d format.',
        ];
    }
}
