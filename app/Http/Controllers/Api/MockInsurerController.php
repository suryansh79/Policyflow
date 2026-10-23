<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mock\Insurer\MockInsurerService;
use App\Models\MockInsurerEvent;
use App\Services\ProviderCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockInsurerController extends Controller
{
    /**
     * Demo/Inspection route: Issue a policy directly against the mock insurer.
     */
    public function issue(Request $request, MockInsurerService $mockService): JsonResponse
    {
        $validated = $request->validate([
            'client_reference' => ['required', 'uuid'],
            'scenario' => ['nullable', 'string'],
        ]);

        $response = $mockService->issue(
            clientReference: $validated['client_reference'],
            payload: $request->all(),
            scenario: $validated['scenario'] ?? null
        );

        return response()->json([
            'status' => $response->status,
            'provider_reference' => $response->providerReference,
            'policy_number' => $response->policyNumber,
            'raw' => $response->rawResponse,
        ]);
    }

    /**
     * Demo/Inspection route: Status lookup keyed by OUR PolicyFlow client reference UUID.
     */
    public function lookup(string $client_reference, MockInsurerService $mockService): JsonResponse
    {
        $response = $mockService->lookup($client_reference);

        if ($response->isNotFound()) {
            return response()->json([
                'message' => 'No policy record found at mock insurer for reference.',
                'error_code' => 'PROVIDER_POLICY_NOT_FOUND',
                'client_reference' => $client_reference,
            ], 404);
        }

        return response()->json([
            'found' => $response->found,
            'status' => $response->status,
            'provider_reference' => $response->providerReference,
            'policy_number' => $response->policyNumber,
            'raw' => $response->rawResponse,
        ]);
    }

    /**
     * Demo/Inspection route: Inspect queued mock insurer events.
     */
    public function events(): JsonResponse
    {
        $events = MockInsurerEvent::orderBy('id', 'asc')->get();

        return response()->json([
            'count' => $events->count(),
            'events' => $events,
        ]);
    }

    /**
     * Demo/Inspection route: Flush due events through the callback service.
     */
    public function flush(ProviderCallbackService $callbackService): JsonResponse
    {
        $events = MockInsurerEvent::where('deliveries_remaining', '>', 0)
            ->orderBy('id', 'asc')
            ->get();

        $results = [];
        foreach ($events as $event) {
            $deliveries = $event->deliveries_remaining;
            for ($i = 0; $i < $deliveries; $i++) {
                $res = $callbackService->handle($event->payload);
                $event->decrement('deliveries_remaining');
                $results[] = $res;
            }
        }

        return response()->json([
            'flushed_count' => count($results),
            'results' => $results,
        ]);
    }
}
