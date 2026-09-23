<?php

use App\Helpers\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This is an API-only app with no `login` route. Without this, the
        // auth:api middleware tries to build a redirect to `route('login')`
        // on every unauthenticated request and crashes with
        // "Route [login] not defined" instead of throwing a clean 401.
        Authenticate::redirectUsing(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* request gets a JSON error response, regardless of
        // whether the client sends `Accept: application/json` — mobile
        // HTTP clients don't always set it, and without this Laravel's
        // default behavior redirects/renders HTML instead.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return ApiResponse::validationError($e->errors());
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error('Unauthenticated.', 401);
            }

            if ($e instanceof ModelNotFoundException) {
                return ApiResponse::error('Resource not found.', 404);
            }

            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error($e->getMessage() ?: 'Request failed.', $e->getStatusCode());
            }

            if (! config('app.debug')) {
                return ApiResponse::error('An unexpected server error occurred.', 500);
            }

            return null;
        });
    })->create();
