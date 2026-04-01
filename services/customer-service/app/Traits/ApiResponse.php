<?php

namespace App\Traits;

trait ApiResponse
{
    protected function success($data = [], $message = null, $code = 200, $meta = [])
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge(['timestamp' => now()->toIso8601String()], $meta),
        ], $code);
    }

    protected function error($message = 'An error occurred', $code = 500, $errors = null, $meta = [])
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $this->getErrorCode($code),
                'message' => $message,
                'details' => $errors,
            ],
            'meta' => array_merge(['timestamp' => now()->toIso8601String()], $meta),
        ], $code);
    }

    private function getErrorCode($statusCode)
    {
        return match ($statusCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            default => 'INTERNAL_SERVER_ERROR',
        };
    }
}
