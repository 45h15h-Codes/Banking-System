<?php

namespace App\Services;

use App\Exceptions\ServiceClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
            throw new ServiceClientException('Customer service unavailable', 'CUSTOMER_SERVICE_UNAVAILABLE', 503);
        }

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new ServiceClientException(
                (string) $response->json('error.message', 'Customer service request failed'),
                (string) $response->json('error.code', 'CUSTOMER_SERVICE_ERROR'),
                $response->status(),
                $response->json('error.details'),
            );
        }

        $customerId = (string) $response->json('data.id', '');
        $kycStatus = (string) $response->json('data.kyc_status', '');

        if ($customerId === '' || $kycStatus === '') {
            throw new ServiceClientException('Invalid customer service response', 'CUSTOMER_SERVICE_ERROR', 502);
        }

        return [
            'id' => $customerId,
            'kyc_status' => $kycStatus,
        ];
    }
}

