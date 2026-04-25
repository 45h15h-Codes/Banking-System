<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class ServiceAuthMiddleware
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('Authentication token required', 'TOKEN_MISSING');
        }

        $authServiceUrl = rtrim((string) config('services.auth_service.url'), '/');
        $internalToken = (string) config('services.auth_service.internal_token');
        $timeout = max(1, (int) config('services.auth_service.timeout', 5));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-Internal-Token' => $internalToken,
                ])
                ->withToken($token)
                ->get("{$authServiceUrl}/api/v1/internal/auth/verify");

            if (! $response->successful()) {
                return $this->error(
                    (string) $response->json('error.message', 'Invalid or expired token'),
                    (string) $response->json('error.code', 'AUTHENTICATION_FAILED'),
                    $response->status(),
                );
            }

            $userData = $response->json('data.user');

            if (! is_array($userData) || empty($userData['uuid']) || empty($userData['role'])) {
                return $this->unauthorized('Invalid authentication response', 'INVALID_AUTH_RESPONSE');
            }

            if (! empty($roles) && ! in_array($userData['role'], $roles, true)) {
                return $this->forbidden('Forbidden. Insufficient role.', 'FORBIDDEN');
            }

            $request->attributes->set('user_id', $userData['uuid']);
            $request->attributes->set('user_role', $userData['role']);
            $request->attributes->set('user_email', $userData['email'] ?? null);

            return $next($request);
        } catch (ConnectionException) {
            return $this->error('Auth service unavailable', 'AUTH_SERVICE_UNAVAILABLE', 503);
        }
    }
}
