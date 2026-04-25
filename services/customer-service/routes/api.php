<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\KycController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Customer Service APIs: handling customer profiles and KYC forms.
*/

Route::get('/v1/health', function () {
    return response()->json([
        'success' => true,
        'data' => [
            'service' => 'customer-service',
            'status' => 'healthy',
            'version' => '1.0.0',
        ],
        'meta' => ['timestamp' => now()->toIso8601String()],
    ]);
});

Route::prefix('v1')->group(function () {
    // Customer Profile Endpoints
    Route::middleware('service.auth')->group(function () {
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/me', [CustomerController::class, 'me']);
        Route::put('/customers/me', [CustomerController::class, 'update']);
        Route::get('/customers/{id}/kyc/status', [CustomerController::class, 'kycStatus']);

        // KYC Document Endpoints
        Route::post('/kyc/documents', [KycController::class, 'uploadDocument']);
        Route::post('/kyc/submit', [KycController::class, 'submitKyc']);
    });

    Route::middleware('service.auth:admin,bank_officer')->group(function () {
        Route::get('/kyc/reviews', [KycController::class, 'reviewQueue']);
        Route::get('/kyc/reviews/{id}', [KycController::class, 'reviewShow']);
        Route::patch('/kyc/{id}/status', [KycController::class, 'updateStatus']);
    });
});
