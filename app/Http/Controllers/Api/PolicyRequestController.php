<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePolicyRequestRequest;
use App\Http\Requests\ListPolicyRequestsRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\PolicyRequestIntakeService;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PolicyRequestController extends Controller
{
    /**
     * List policy requests with status/agent filter and pagination.
     */
    public function index(ListPolicyRequestsRequest $request): AnonymousResourceCollection
    {
        $query = PolicyRequest::query();

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->validated('agent_id'));
        }

        $perPage = (int) $request->input('per_page', 15);

        $paginated = $query->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return PolicyRequestResource::collection($paginated);
    }

    /**
     * Intake endpoint: Create a new policy request or safely return an existing replayed request.
     */
    public function store(
        CreatePolicyRequestRequest $request,
        PolicyRequestIntakeService $intakeService
    ): JsonResponse {
        $result = $intakeService->handle($request->validated());

        $resource = new PolicyRequestResource($result['request']);

        if ($result['is_replayed']) {
            return $resource->response()
                ->setStatusCode(200)
                ->header('Idempotent-Replayed', 'true');
        }

        return $resource->response()->setStatusCode(201);
    }

    /**
     * Detail endpoint: Retrieve status, premium, references, attempts, and audit trail.
     */
    public function show(string $id): JsonResponse
    {
        $policyRequest = PolicyRequest::with(['stateHistory', 'attempts'])->find($id);

        if (! $policyRequest) {
            throw new ApiException('The requested policy request was not found.', 'REQUEST_NOT_FOUND', 404);
        }

        $resource = (new PolicyRequestResource($policyRequest))->withDetails(true);

        return response()->json($resource->resolve(), 200);
    }

    /**
     * Issuance submission endpoint: Start or safely resume policy issuance.
     */
    public function submit(string $id, SubmissionService $submissionService): JsonResponse
    {
        $result = $submissionService->submit($id);

        $payload = (new PolicyRequestResource($result['request']))->resolve();
        $payload['submission'] = [
            'outcome' => $result['outcome'],
            'provider_called' => $result['provider_called'],
        ];

        return response()->json($payload, 200);
    }
}
