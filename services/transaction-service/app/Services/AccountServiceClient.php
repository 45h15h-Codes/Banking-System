<?php

namespace App\Services;

use App\Exceptions\ServiceClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AccountServiceClient
{
    /**
     * @return array{id: string, customer_id: string, status: string, balance_minor: int}|null
     */
    public function fetchAccount(string $accountId): ?array
    {
        $response = $this->request('get', "/api/v1/internal/accounts/{$accountId}");

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            $this->throwClientException($response);
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new ServiceClientException('Invalid account service response', 'ACCOUNT_SERVICE_ERROR', 502);
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'customer_id' => (string) ($data['customer_id'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'balance_minor' => (int) ($data['balance_minor'] ?? 0),
        ];
    }

    /**
     * @return array{account_id: string, customer_id: string, balance_minor: int}
     */
    public function adjustBalance(string $accountId, int $deltaMinor): array
    {
        $response = $this->request('post', "/api/v1/internal/accounts/{$accountId}/adjust-balance", [
            'delta_minor' => $deltaMinor,
        ]);

        if ($response->failed()) {
            $this->throwClientException($response);
        }

        return [
            'account_id' => (string) $response->json('data.account_id', ''),
            'customer_id' => (string) $response->json('data.customer_id', ''),
            'balance_minor' => (int) $response->json('data.balance_minor', 0),
        ];
    }

    /**
     * @return array{
     *   source: array{account_id: string, customer_id: string, balance_minor: int},
     *   destination: array{account_id: string, customer_id: string, balance_minor: int}
     * }
     */
    public function transfer(string $sourceAccountId, string $destinationAccountId, int $amountMinor): array
    {
        $response = $this->request('post', '/api/v1/internal/accounts/transfer', [
            'source_account_id' => $sourceAccountId,
            'destination_account_id' => $destinationAccountId,
            'amount_minor' => $amountMinor,
        ]);

        if ($response->failed()) {
            $this->throwClientException($response);
        }

        return [
            'source' => [
                'account_id' => (string) $response->json('data.source.account_id', ''),
                'customer_id' => (string) $response->json('data.source.customer_id', ''),
                'balance_minor' => (int) $response->json('data.source.balance_minor', 0),
            ],
            'destination' => [
                'account_id' => (string) $response->json('data.destination.account_id', ''),
                'customer_id' => (string) $response->json('data.destination.customer_id', ''),
                'balance_minor' => (int) $response->json('data.destination.balance_minor', 0),
            ],
        ];
    }

    private function request(string $method, string $path, ?array $payload = null): \Illuminate\Http\Client\Response
    {
        $baseUrl = rtrim((string) config('services.account_service.url'), '/');
        $internalToken = (string) config('services.account_service.internal_token');
        $timeout = max(1, (int) config('services.account_service.timeout', 5));

        try {
            $request = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'X-Internal-Token' => $internalToken,
                ]);

            if ($method === 'post') {
                return $request->post("{$baseUrl}{$path}", $payload ?? []);
            }

            return $request->get("{$baseUrl}{$path}");
        } catch (ConnectionException) {
            throw new ServiceClientException('Account service unavailable', 'ACCOUNT_SERVICE_UNAVAILABLE', 503);
        }
    }

    private function throwClientException(\Illuminate\Http\Client\Response $response): never
    {
        throw new ServiceClientException(
            (string) $response->json('error.message', 'Account service request failed'),
            (string) $response->json('error.code', 'ACCOUNT_SERVICE_ERROR'),
            $response->status(),
            $response->json('error.details'),
        );
    }
}

