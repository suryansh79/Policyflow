<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PolicyStatus;
use App\Models\PolicyRequest;
use App\Services\ReconciliationService;
use Illuminate\Console\Command;

class ReconcilePoliciesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'policy:reconcile {--limit=50 : Maximum requests to process in one run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile policies in NEEDS_RECONCILIATION or stale PENDING_CONFIRMATION state';

    public function handle(ReconciliationService $reconciliationService): int
    {
        $limit = (int) $this->option('limit');
        $staleThreshold = now()->subSeconds((int) config('policyflow.submission_lease_seconds', 120));

        // Find requests in NEEDS_RECONCILIATION or stale PENDING_CONFIRMATION
        $requests = PolicyRequest::where('status', PolicyStatus::NEEDS_RECONCILIATION)
            ->orWhere(function ($query) use ($staleThreshold) {
                $query->where('status', PolicyStatus::PENDING_CONFIRMATION)
                    ->where('updated_at', '<=', $staleThreshold);
            })
            ->limit($limit)
            ->get();

        if ($requests->isEmpty()) {
            $this->info('No policies currently require reconciliation.');

            return Command::SUCCESS;
        }

        $this->info("Found {$requests->count()} policy request(s) requiring reconciliation.");

        $rows = [];
        foreach ($requests as $request) {
            $result = $reconciliationService->reconcile($request);
            $rows[] = [
                $request->id,
                $request->client_request_id,
                $request->status->value,
                $result['request']->status->value,
                $result['outcome'],
                $result['provider_called'] ? 'Yes' : 'No',
            ];
        }

        $this->table(['Request ID', 'Client Req ID', 'Before', 'After', 'Outcome', 'Provider Called'], $rows);

        return Command::SUCCESS;
    }
}
