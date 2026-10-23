<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderCallbackRequest;
use App\Services\ProviderCallbackService;
use Illuminate\Http\JsonResponse;

class ProviderCallbackController extends Controller
{
    /**
     * Inbound provider callback receiver endpoint.
     */
    public function handle(
        ProviderCallbackRequest $request,
        ProviderCallbackService $callbackService
    ): JsonResponse {
        $result = $callbackService->handle($request->validated());

        return response()->json($result, 200);
    }
}
