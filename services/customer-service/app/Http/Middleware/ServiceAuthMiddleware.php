<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class ServiceAuthMiddleware
{
    use ApiResponse;

    /**
     * Handle an incoming request by verifying token with Auth Service.
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->error('Authentication token required', 401);
        }

        // Call the auth-service internal verify endpoint
        $authServiceUrl = rtrim((string) env('AUTH_SERVICE_URL', 'http://localhost:8001'), '/');
        $internalSecret = env('INTERNAL_SERVICE_TOKEN', env('INTERNAL_SERVICE_SECRET', 'secret_key'));

        try {
            $response = Http::withHeaders([
                'X-Internal-Token' => $internalSecret,
            ])->withToken($token)->get("{$authServiceUrl}/api/v1/internal/auth/verify");

            if (! $response->successful()) {
                $msg = $response->json('error.message', 'Invalid or expired token');

                return $this->error($msg, $response->status());
            }

            $userData = $response->json('data.user');

            if (! is_array($userData) || empty($userData['uuid']) || empty($userData['role'])) {
                return $this->error('Invalid authentication response', 401);
            }

            // Optionally check roles if passed as middleware parameters e.g., service.auth:admin
            if (! empty($roles)) {
                if (! in_array($userData['role'], $roles, true)) {
                    return $this->error('Forbidden. Insufficient role.', 403);
                }
            }

            // Append user info to the request for controllers to use
            $request->attributes->set('user_id', $userData['uuid']);
            $request->attributes->set('user_role', $userData['role']);
            $request->attributes->set('user_email', $userData['email']);

            return $next($request);
        } catch (\Exception) {
            return $this->error('Auth service unavailable', 503);
        }
    }
}
