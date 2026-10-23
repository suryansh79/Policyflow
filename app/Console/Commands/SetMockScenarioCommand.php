<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MockInsurerPolicy;
use App\Models\PolicyRequest;
use Illuminate\Console\Command;

class SetMockScenarioCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mock-insurer:set-scenario 
                            {request_id : Policy request UUID or client_request_id} 
                            {scenario : Scenario (SUCCESS, TEMPORARY_FAILURE, TIMEOUT_AFTER_ISSUE, DELAYED_CALLBACK, DUPLICATE_CALLBACK, PERMANENT_FAILURE)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set or switch the mock insurer response scenario for a specific policy request';

    public function handle(): int
    {
        $identifier = (string) $this->argument('request_id');
        $scenario = strtoupper((string) $this->argument('scenario'));

        $allowed = [
            'SUCCESS',
            'TEMPORARY_FAILURE',
            'TIMEOUT_AFTER_ISSUE',
            'DELAYED_CALLBACK',
            'DUPLICATE_CALLBACK',
            'PERMANENT_FAILURE',
        ];

        if (! in_array($scenario, $allowed, true)) {
            $this->error("Invalid scenario '{$scenario}'. Allowed scenarios: ".implode(', ', $allowed));

            return Command::FAILURE;
        }

        // Find policy request by UUID or client_request_id
        $policyRequest = PolicyRequest::where('id', $identifier)
            ->orWhere('client_request_id', $identifier)
            ->first();

        if (! $policyRequest) {
            $this->error("Policy request '{$identifier}' not found.");

            return Command::FAILURE;
        }

        // Update scenario on policy request
        $policyRequest->mock_scenario = $scenario;
        $policyRequest->save();

        // Also update existing mock insurer policy record if already initialized
        MockInsurerPolicy::where('client_reference', $policyRequest->id)->update([
            'scenario' => $scenario,
        ]);

        $this->info("Scenario for request {$policyRequest->id} ('{$policyRequest->client_request_id}') updated to [{$scenario}].");

        return Command::SUCCESS;
    }
}
