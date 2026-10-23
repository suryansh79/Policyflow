<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepend ForceJsonResponse so all /api/* requests are treated as JSON requests
        $middleware->prependToGroup('api', ForceJsonResponse::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // Validation exceptions use Laravel's default shape {message, errors} so assertJsonValidationErrors works
                if ($e instanceof ValidationException) {
                    return null;
                }

                // Domain/API exceptions with machine error codes
                if ($e instanceof ApiException) {
                    $payload = [
                        'message' => $e->getMessage(),
                        'error_code' => $e->getErrorCode(),
                    ];
                    if (! empty($e->getDetails())) {
                        $payload['details'] = $e->getDetails();
                    }

                    return response()->json($payload, $e->getStatusCode());
                }

                // 404 Model Not Found
                if ($e instanceof ModelNotFoundException) {
                    return response()->json([
                        'message' => 'The requested policy request was not found.',
                        'error_code' => 'REQUEST_NOT_FOUND',
                    ], 404);
                }

                // 404 HTTP Not Found
                if ($e instanceof NotFoundHttpException) {
                    return response()->json([
                        'message' => 'The requested endpoint or resource was not found.',
                        'error_code' => 'REQUEST_NOT_FOUND',
                    ], 404);
                }

                // 405 Method Not Allowed
                if ($e instanceof MethodNotAllowedHttpException) {
                    return response()->json([
                        'message' => 'The HTTP method is not allowed for this endpoint.',
                        'error_code' => 'METHOD_NOT_ALLOWED',
                    ], 405);
                }

                // Production safety: never leak stack traces when APP_DEBUG=false
                if (! config('app.debug')) {
                    return response()->json([
                        'message' => 'An unexpected server error occurred.',
                        'error_code' => 'INTERNAL_SERVER_ERROR',
                    ], 500);
                }
            }

            return null;
        });
    })->create();
