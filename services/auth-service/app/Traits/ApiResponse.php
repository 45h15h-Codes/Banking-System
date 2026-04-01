<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * Standardized API response format for BankCore microservices.
 * Every service MUST use this trait to keep responses consistent.
 *
 * Success: { "success": true, "data": {...}, "message": "...", "meta": { "timestamp": "..." } }
 * Error:   { "success": false, "error": { "code": "...", "message": "...", "details": null }, "meta": { "timestamp": "..." } }
 */
trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'Success', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }

    protected function created(mixed $data = null, string $message = 'Created successfully'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    protected function error(
        string $message = 'Something went wrong',
        string $code = 'SERVER_ERROR',
        int $status = 500,
        mixed $details = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], $status);
    }

    protected function unauthorized(string $message = 'Unauthorized', string $code = 'UNAUTHORIZED'): JsonResponse
    {
        return $this->error($message, $code, 401);
    }

    protected function forbidden(string $message = 'Forbidden', string $code = 'FORBIDDEN'): JsonResponse
    {
        return $this->error($message, $code, 403);
    }

    protected function notFound(string $message = 'Resource not found', string $code = 'NOT_FOUND'): JsonResponse
    {
        return $this->error($message, $code, 404);
    }

    protected function validationError(mixed $details, string $message = 'Validation failed'): JsonResponse
    {
        return $this->error($message, 'VALIDATION_ERROR', 422, $details);
    }

    protected function tooManyRequests(string $message = 'Too many requests'): JsonResponse
    {
        return $this->error($message, 'RATE_LIMIT_EXCEEDED', 429);
    }
}
