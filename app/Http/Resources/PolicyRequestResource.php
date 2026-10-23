<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PolicyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PolicyRequest
 */
class PolicyRequestResource extends JsonResource
{
    /**
     * Flag to include detailed audit trail and attempt history.
     */
    protected bool $includeDetails = false;

    /**
     * Include audit trail and attempt list in detail views.
     */
    public function withDetails(bool $include = true): self
    {
        $this->includeDetails = $include;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rupees = intdiv($this->premium_minor, 100);
        $paise = $this->premium_minor % 100;
        $formattedPremium = sprintf('%d.%02d', $rupees, $paise);

        $data = [
            'id' => $this->id,
            'client_request_id' => $this->client_request_id,
            'agent_id' => $this->agent_id,
            'customer_name' => $this->customer_name,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d') ?? (string) $this->date_of_birth,
            'destination' => $this->destination,
            'trip_start' => $this->trip_start?->format('Y-m-d') ?? (string) $this->trip_start,
            'trip_end' => $this->trip_end?->format('Y-m-d') ?? (string) $this->trip_end,
            'plan_code' => $this->plan_code,
            'sum_insured' => (int) $this->sum_insured,
            'travel_days' => (int) $this->travel_days,
            'status' => $this->status->value,
            'premium' => $formattedPremium,
            'premium_currency' => $this->premium_currency,
            'premium_breakdown' => $this->premium_breakdown,
            'provider_reference' => $this->provider_reference,
            'policy_number' => $this->policy_number,
            'attempt_count' => (int) $this->attempt_count,
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->includeDetails) {
            $data['audit_trail'] = $this->stateHistory->map(function ($h) {
                return [
                    'from_status' => $h->from_status?->value,
                    'to_status' => $h->to_status->value,
                    'reason' => $h->reason,
                    'source' => $h->source,
                    'context' => $h->context,
                    'created_at' => $h->created_at?->toIso8601String(),
                ];
            })->values()->all();

            $data['attempts'] = $this->attempts->map(function ($a) {
                return [
                    'id' => $a->id,
                    'type' => $a->type,
                    'sequence' => $a->sequence,
                    'outcome' => $a->outcome,
                    'http_status' => $a->http_status,
                    'error_code' => $a->error_code,
                    'error_message' => $a->error_message,
                    'response' => $a->response,
                    'started_at' => $a->started_at?->toIso8601String(),
                    'finished_at' => $a->finished_at?->toIso8601String(),
                    'duration_ms' => $a->duration_ms,
                ];
            })->values()->all();
        }

        return $data;
    }
}
