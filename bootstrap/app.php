<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserve Laravel's secure default middleware stack.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') || ! $request->expectsJson()) {
                return null;
            }

            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ValidationException => 422,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };

            $code = match ($status) {
                401 => 'UNAUTHENTICATED',
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                422 => 'VALIDATION_FAILED',
                429 => 'RATE_LIMITED',
                default => $status >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_FAILED',
            };

            $title = match ($status) {
                401 => 'Authentication required',
                403 => 'Forbidden',
                404 => 'Resource not found',
                422 => 'Request validation failed',
                429 => 'Too many requests',
                default => $status >= 500 ? 'Internal server error' : 'Request failed',
            };

            $candidateRequestId = $request->headers->get('X-Request-ID');
            $requestId = is_string($candidateRequestId)
                && preg_match('/\\A[A-Za-z0-9._:-]{1,128}\\z/D', $candidateRequestId) === 1
                    ? $candidateRequestId
                    : (string) Str::uuid();

            $problem = [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $exception instanceof ValidationException
                    ? 'One or more fields are invalid.'
                    : 'The request could not be completed.',
                'instance' => $request->getPathInfo(),
                'code' => $code,
                'request_id' => $requestId,
            ];

            if ($exception instanceof ValidationException) {
                $problem['errors'] = $exception->errors();
            }

            return response()->json($problem, $status, [
                'Content-Type' => 'application/problem+json',
            ]);
        });
    })
    ->create();
