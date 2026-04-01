<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal service-to-service authentication middleware.
 * Other microservices call GET /api/v1/internal/auth/verify with a
 * shared X-Internal-Token header to verify a user's Sanctum token.
 *
 * Per api-security-best-practices skill: internal APIs must also be authenticated.
 */
class InternalTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $internalToken = $request->header('X-Internal-Token');
        $expectedToken = config('services.internal.token');

        if (! $internalToken || ! $expectedToken || ! hash_equals($expectedToken, $internalToken)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INTERNAL_AUTH_FAILED',
                    'message' => 'Invalid internal service token',
                    'details' => null,
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 403);
        }

        return $next($request);
    }
}
