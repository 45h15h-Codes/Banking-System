<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\AuthAuditLog;
use App\Models\RefreshToken;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * AuthController — handles all public-facing auth operations.
 *
 * Skills applied:
 * - auth-implementation-patterns: JWT/token lifecycle, refresh tokens, secure logout
 * - api-design-principles: versioned endpoints, consistent response format
 * - api-security-best-practices: input validation, no sensitive data in responses, audit logging
 * - api-endpoint-builder: proper HTTP status codes, error handling
 * - architecture-patterns: thin controller, fat model approach
 */
class AuthController extends Controller
{
    use ApiResponse;

    // Token expiration (in minutes) — 15 min for access, 1 day for refresh
    private const ACCESS_TOKEN_EXPIRY_MINUTES = 15;
    private const REFRESH_TOKEN_EXPIRY_HOURS = 24;

    /**
     * POST /api/v1/auth/register
     *
     * Register a new customer user.
     * Admin/officer accounts are created via seeder or admin panel only.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'], // auto-hashed via cast
            'role' => 'customer', // public registration = customer only
        ]);

        // Issue tokens
        $accessToken = $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes(self::ACCESS_TOKEN_EXPIRY_MINUTES)
        );

        $refreshToken = $this->createRefreshToken($user, $request);

        // Audit log
        AuthAuditLog::record(
            'register',
            $user->id,
            $request->ip(),
            $request->userAgent(),
            ['email' => $user->email]
        );

        return $this->created([
            'user' => $this->formatUser($user),
            'token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_EXPIRY_MINUTES * 60, // seconds
        ], 'Registration successful');
    }

    /**
     * POST /api/v1/auth/login
     *
     * Authenticate user and issue access + refresh tokens.
     * Never reveals whether the email exists — always "Invalid credentials".
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        // Security: generic message whether user not found or password wrong
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            // Audit failed login
            AuthAuditLog::record(
                'failed_login',
                $user?->id,
                $request->ip(),
                $request->userAgent(),
                ['email' => $validated['email']]
            );

            return $this->unauthorized('Invalid credentials', 'INVALID_CREDENTIALS');
        }

        // Check if account is active
        if (! $user->is_active) {
            return $this->forbidden('Account is deactivated. Contact support.', 'ACCOUNT_DEACTIVATED');
        }

        // Revoke all existing tokens (single-session enforcement)
        $user->tokens()->delete();

        // Issue new access token
        $accessToken = $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes(self::ACCESS_TOKEN_EXPIRY_MINUTES)
        );

        $refreshToken = $this->createRefreshToken($user, $request);

        // Update last login info
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Audit
        AuthAuditLog::record(
            'login',
            $user->id,
            $request->ip(),
            $request->userAgent()
        );

        return $this->success([
            'user' => $this->formatUser($user),
            'token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_EXPIRY_MINUTES * 60,
        ], 'Login successful');
    }

    /**
     * GET /api/v1/auth/me
     *
     * Return authenticated user's profile.
     * Requires Bearer token.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->success([
            'user' => $this->formatUser($user),
        ], 'User profile retrieved');
    }

    /**
     * POST /api/v1/auth/refresh
     *
     * Issue a new access token using a valid refresh token.
     * The old access tokens are revoked, refresh token stays valid until its own expiry.
     */
    public function refresh(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => ['required', 'string', 'size:64'],
        ]);

        $hashedToken = hash('sha256', $request->refresh_token);

        $refreshToken = RefreshToken::where('token', $hashedToken)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $refreshToken) {
            return $this->unauthorized('Invalid or expired refresh token', 'INVALID_REFRESH_TOKEN');
        }

        $user = $refreshToken->user;

        if (! $user || ! $user->is_active) {
            return $this->forbidden('Account is deactivated', 'ACCOUNT_DEACTIVATED');
        }

        // Revoke all existing access tokens
        $user->tokens()->delete();

        // Issue new access token
        $accessToken = $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes(self::ACCESS_TOKEN_EXPIRY_MINUTES)
        );

        // Audit
        AuthAuditLog::record(
            'token_refresh',
            $user->id,
            $request->ip(),
            $request->userAgent()
        );

        return $this->success([
            'token' => $accessToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_EXPIRY_MINUTES * 60,
        ], 'Token refreshed successfully');
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revoke all tokens (access + refresh) for the authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoke all Sanctum tokens
        $user->tokens()->delete();

        // Revoke all refresh tokens
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        // Audit
        AuthAuditLog::record(
            'logout',
            $user->id,
            $request->ip(),
            $request->userAgent()
        );

        return $this->success(null, 'Logged out successfully');
    }

    // ─── Private Helpers ──────────────────────────────────────

    /**
     * Create a refresh token, store its hash in DB, return the plaintext.
     */
    private function createRefreshToken(User $user, Request $request): string
    {
        // Revoke existing refresh tokens for this user
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $plainToken = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainToken),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'expires_at' => now()->addHours(self::REFRESH_TOKEN_EXPIRY_HOURS),
        ]);

        return $plainToken;
    }

    /**
     * Format user data for API response. Never expose password or tokens.
     */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at->toIso8601String(),
        ];
    }
}
