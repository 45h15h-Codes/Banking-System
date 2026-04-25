<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CustomerProfileService
{
    /**
     * @return array{id: string, kyc_status: string}|null
     */
    public function fetchCurrentCustomer(string $bearerToken): ?array
    {
        $customerServiceUrl = rtrim((string) config('services.customer_service.url'), '/');
        $timeout = max(1, (int) config('services.customer_service.timeout', 5));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($bearerToken)
                ->get("{$customerServiceUrl}/api/v1/customers/me");
        } catch (ConnectionException) {
            throw new RuntimeException('Customer service unavailable');
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException(
                (string) $response->json('error.message', 'Customer service request failed'),
            );
        }

        $customerId = (string) $response->json('data.id', '');
        $kycStatus = (string) $response->json('data.kyc_status', '');

        if ($customerId === '' || $kycStatus === '') {
            throw new RuntimeException('Invalid customer service response');
        }

        return [
            'id' => $customerId,
            'kyc_status' => $kycStatus,
        ];
    }
}
