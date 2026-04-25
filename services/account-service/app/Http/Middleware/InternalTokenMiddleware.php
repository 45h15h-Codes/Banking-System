<?php

namespace App\Http\Middleware;

use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalTokenMiddleware
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $receivedToken = (string) $request->header('X-Internal-Token', '');
        $expectedToken = (string) config('services.auth_service.internal_token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $receivedToken)) {
            return $this->unauthorized('Invalid internal service token', 'INVALID_INTERNAL_TOKEN');
        }

        return $next($request);
    }
}

