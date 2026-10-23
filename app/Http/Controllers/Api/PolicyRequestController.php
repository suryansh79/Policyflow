<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePolicyRequestRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Services\PolicyRequestIntakeService;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;

class PolicyRequestController extends Controller
{
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
