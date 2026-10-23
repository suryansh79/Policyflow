<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MockInsurerEvent;
use App\Services\ProviderCallbackService;
use Illuminate\Console\Command;

class DeliverMockEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mock-insurer:deliver-events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deliver pending mock insurer callback events to the PolicyFlow receiver';

    public function handle(ProviderCallbackService $callbackService): int
    {
        $events = MockInsurerEvent::where('deliveries_remaining', '>', 0)
            ->orderBy('id', 'asc')
            ->get();

        if ($events->isEmpty()) {
            $this->info('No due mock insurer events to deliver.');

            return Command::SUCCESS;
        }

        $this->info("Found {$events->count()} due event delivery(s).");

        $rows = [];
        foreach ($events as $event) {
            $deliveries = $event->deliveries_remaining;
            for ($i = 0; $i < $deliveries; $i++) {
                $result = $callbackService->handle($event->payload);
                $event->decrement('deliveries_remaining');

                $rows[] = [
                    $event->event_id,
                    $event->payload['event_type'],
                    $result['result'],
                    $result['request_status'],
                ];
            }
        }

        $this->table(['Event ID', 'Type', 'Result', 'Request Status'], $rows);

        return Command::SUCCESS;
    }
}
