<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_name_and_dob_are_never_logged(): void
    {
        $sensitiveCustomerName = 'SecretCustomerNameAlpha99';
        $sensitiveDob = '1987-12-31';

        $loggedData = [];

        // Custom log listener to capture all log events and their context
        Log::listen(function ($message) use (&$loggedData) {
            $loggedData[] = [
                'message' => $message->message,
                'context' => json_encode($message->context),
            ];
        });

        // 1. Policy Intake
        $payload = [
            'client_request_id' => 'AGT-PRIVACY-01',
            'agent_id' => 'AGENT-PRIVACY',
            'customer_name' => $sensitiveCustomerName,
            'date_of_birth' => $sensitiveDob,
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-10',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);
        $response->assertStatus(201);
        $requestId = $response->json('id');

        // 2. Replay Intake
        $replayResponse = $this->postJson('/api/policy-requests', $payload);
        $replayResponse->assertStatus(200);

        // 3. Submit policy issuance
        $submitResponse = $this->postJson("/api/policy-requests/{$requestId}/submit");
        $submitResponse->assertStatus(200);

        // 4. Send callback
        $callbackPayload = [
            'event_id' => 'evt_privacy_test_01',
            'event_type' => 'POLICY_ISSUED',
            'provider_reference' => $submitResponse->json('provider_reference'),
            'client_reference' => $requestId,
            'policy_number' => $submitResponse->json('policy_number'),
            'occurred_at' => now()->toIso8601String(),
        ];
        $callbackResponse = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $callbackResponse->assertStatus(200);

        // Verify log entries were captured
        $this->assertNotEmpty($loggedData, 'Expected system to produce structured log messages during operations.');

        // Assert customer name and DOB are NEVER contained in any message or context
        foreach ($loggedData as $entry) {
            $fullLogText = $entry['message'].' '.$entry['context'];

            $this->assertStringNotContainsString(
                $sensitiveCustomerName,
                $fullLogText,
                "Log entry contains confidential customer name PII: {$fullLogText}"
            );

            $this->assertStringNotContainsString(
                $sensitiveDob,
                $fullLogText,
                "Log entry contains confidential date of birth PII: {$fullLogText}"
            );
        }

        // Verify that structured identifiers (request_id, agent_id) WERE captured
        $allLogsCombined = json_encode($loggedData);
        $this->assertStringContainsString($requestId, $allLogsCombined);
        $this->assertStringContainsString('AGENT-PRIVACY', $allLogsCombined);
    }
}
