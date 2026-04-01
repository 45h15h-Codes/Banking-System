<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * InternalAuthController — service-to-service token verification.
 *
 * Other microservices call this endpoint with:
 *   - Authorization: Bearer <user_token>   (the token to verify)
 *   - X-Internal-Token: <shared_secret>    (service-to-service auth)
 *
 * This is how account-service, customer-service etc. verify
 * that a request's JWT/token is valid without having direct DB access.
 */
class InternalAuthController extends Controller
{
    use ApiResponse;

    /**
     * GET /api/v1/internal/auth/verify
     *
     * Verify a Sanctum token sent by another microservice.
     * Protected by X-Internal-Token middleware.
     */
    public function verify(Request $request): JsonResponse
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return $this->unauthorized('Bearer token is required', 'TOKEN_MISSING');
        }

        // Find the token in the personal_access_tokens table
        $accessToken = PersonalAccessToken::findToken($bearerToken);

        if (! $accessToken) {
            return $this->unauthorized('Invalid token', 'INVALID_TOKEN');
        }

        // Check if token is expired
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return $this->unauthorized('Token has expired', 'TOKEN_EXPIRED');
        }

        // Get the user
        /** @var User $user */
        $user = $accessToken->tokenable;

        if (! $user || ! $user->is_active) {
            return $this->forbidden('User account is deactivated', 'ACCOUNT_DEACTIVATED');
        }

        // Update last_used_at
        $accessToken->forceFill(['last_used_at' => now()])->save();

        return $this->success([
            'valid' => true,
            'user' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
            ],
        ], 'Token is valid');
    }
}
