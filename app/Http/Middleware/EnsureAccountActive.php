<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating only blacklists the token it was done with; this signs the
 * account out on every other device too (the app treats 401 as signed out).
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDeactivated()) {
            return ApiResponse::error('This account is deactivated. Log in again to reactivate it.', 401);
        }

        return $next($request);
    }
}
