<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based access control middleware.
 * Usage in routes: ->middleware('role:admin,bank_officer')
 *
 * Per architecture-patterns skill: enforce authorization at the middleware layer,
 * keeping controllers focused on business logic.
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required',
                    'details' => null,
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 401);
        }

        if (! empty($roles) && ! in_array($user->role, $roles, true)) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to access this resource',
                    'details' => null,
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 403);
        }

        return $next($request);
    }
}
