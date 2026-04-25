<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransactionServiceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_routes_require_bearer_token(): void
    {
        $this->postJson('/api/v1/transactions/deposit', [
            'account_id' => '00000000-0000-0000-0000-000000000001',
            'amount_minor' => 1000,
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'TOKEN_MISSING');
    }

    public function test_customer_can_deposit_and_ledger_is_written(): void
    {
        $accountId = '00000000-0000-0000-0000-000000000001';
        $this->fakeExternalDependencies(function (HttpRequest $request) use ($accountId) {
            $url = $request->url();

            if (str_contains($url, '/api/v1/internal/auth/verify')) {
                return Http::response($this->authPayload('customer-user-1', 'customer'), 200);
            }

            if (str_contains($url, '/api/v1/customers/me')) {
                return Http::response($this->customerPayload('customer-profile-1'), 200);
            }

            if (str_ends_with($url, "/api/v1/internal/accounts/{$accountId}") && $request->method() === 'GET') {
                return Http::response($this->accountPayload($accountId, 'customer-profile-1', 5000), 200);
            }

            if (str_contains($url, '/adjust-balance') && $request->method() === 'POST') {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'account_id' => $accountId,
                        'customer_id' => 'customer-profile-1',
                        'balance_minor' => 7000,
                    ],
                    'meta' => ['timestamp' => now()->toIso8601String()],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->withBearerToken()->postJson('/api/v1/transactions/deposit', [
            'account_id' => $accountId,
            'amount_minor' => 2000,
            'channel' => 'cash',
            'description' => 'Cash deposit',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $accountId)
            ->assertJsonPath('data.type', 'credit')
            ->assertJsonPath('data.amount_minor', 2000)
            ->assertJsonPath('data.balance_after_minor', 7000)
            ->assertJsonPath('data.channel', 'cash');

        $this->assertDatabaseHas('transactions', [
            'account_id' => $accountId,
            'type' => 'credit',
            'amount_minor' => 2000,
            'balance_after_minor' => 7000,
            'channel' => 'cash',
            'status' => 'completed',
        ]);
        $this->assertDatabaseCount('transaction_audit_logs', 1);
    }

    public function test_withdrawal_rejects_insufficient_balance(): void
    {
        $accountId = '00000000-0000-0000-0000-000000000002';
        $this->fakeExternalDependencies(function (HttpRequest $request) use ($accountId) {
            $url = $request->url();

            if (str_contains($url, '/api/v1/internal/auth/verify')) {
                return Http::response($this->authPayload('customer-user-1', 'customer'), 200);
            }

            if (str_contains($url, '/api/v1/customers/me')) {
                return Http::response($this->customerPayload('customer-profile-1'), 200);
            }

            if (str_ends_with($url, "/api/v1/internal/accounts/{$accountId}") && $request->method() === 'GET') {
                return Http::response($this->accountPayload($accountId, 'customer-profile-1', 1000), 200);
            }

            if (str_contains($url, '/adjust-balance') && $request->method() === 'POST') {
                return Http::response([
                    'success' => false,
                    'error' => [
                        'code' => 'INSUFFICIENT_BALANCE',
                        'message' => 'Insufficient balance',
                        'details' => [
                            'available_balance_minor' => 1000,
                            'requested_delta_minor' => -1500,
                        ],
                    ],
                ], 422);
            }

            return Http::response([], 404);
        });

        $this->withBearerToken()->postJson('/api/v1/transactions/withdraw', [
            'account_id' => $accountId,
            'amount_minor' => 1500,
            'channel' => 'internal',
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_audit_logs', 0);
    }

    public function test_transfer_creates_debit_and_credit_ledger_entries(): void
    {
        $sourceId = '00000000-0000-0000-0000-000000000003';
        $destinationId = '00000000-0000-0000-0000-000000000004';

        $this->fakeExternalDependencies(function (HttpRequest $request) use ($sourceId, $destinationId) {
            $url = $request->url();

            if (str_contains($url, '/api/v1/internal/auth/verify')) {
                return Http::response($this->authPayload('customer-user-1', 'customer'), 200);
            }

            if (str_contains($url, '/api/v1/customers/me')) {
                return Http::response($this->customerPayload('customer-profile-1'), 200);
            }

            if (str_ends_with($url, "/api/v1/internal/accounts/{$sourceId}") && $request->method() === 'GET') {
                return Http::response($this->accountPayload($sourceId, 'customer-profile-1', 7000), 200);
            }

            if (str_ends_with($url, "/api/v1/internal/accounts/{$destinationId}") && $request->method() === 'GET') {
                return Http::response($this->accountPayload($destinationId, 'customer-profile-2', 1000), 200);
            }

            if (str_ends_with($url, '/api/v1/internal/accounts/transfer') && $request->method() === 'POST') {
                return Http::response([
                    'success' => true,
                    'data' => [
                        'source' => [
                            'account_id' => $sourceId,
                            'customer_id' => 'customer-profile-1',
                            'balance_minor' => 5000,
                        ],
                        'destination' => [
                            'account_id' => $destinationId,
                            'customer_id' => 'customer-profile-2',
                            'balance_minor' => 3000,
                        ],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->withBearerToken()->postJson('/api/v1/transactions/transfer', [
            'source_account_id' => $sourceId,
            'destination_account_id' => $destinationId,
            'amount_minor' => 2000,
            'channel' => 'internal',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.debit.type', 'debit')
            ->assertJsonPath('data.debit.account_id', $sourceId)
            ->assertJsonPath('data.credit.type', 'credit')
            ->assertJsonPath('data.credit.account_id', $destinationId);

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('transaction_audit_logs', 2);

        $debit = Transaction::query()->where('account_id', $sourceId)->firstOrFail();
        $credit = Transaction::query()->where('account_id', $destinationId)->firstOrFail();
        $this->assertSame($debit->transfer_group_id, $credit->transfer_group_id);
        $this->assertNotNull($debit->transfer_group_id);
    }

    public function test_customer_cannot_deposit_to_another_customers_account(): void
    {
        $accountId = '00000000-0000-0000-0000-000000000005';

        $this->fakeExternalDependencies(function (HttpRequest $request) use ($accountId) {
            $url = $request->url();

            if (str_contains($url, '/api/v1/internal/auth/verify')) {
                return Http::response($this->authPayload('customer-user-1', 'customer'), 200);
            }

            if (str_contains($url, '/api/v1/customers/me')) {
                return Http::response($this->customerPayload('customer-profile-1'), 200);
            }

            if (str_ends_with($url, "/api/v1/internal/accounts/{$accountId}") && $request->method() === 'GET') {
                return Http::response($this->accountPayload($accountId, 'customer-profile-2', 4000), 200);
            }

            return Http::response([], 404);
        });

        $this->withBearerToken()->postJson('/api/v1/transactions/deposit', [
            'account_id' => $accountId,
            'amount_minor' => 500,
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_history_supports_filters_and_pagination(): void
    {
        $accountId = '00000000-0000-0000-0000-000000000006';

        Transaction::query()->create([
            'account_id' => $accountId,
            'type' => 'credit',
            'amount_minor' => 5000,
            'balance_after_minor' => 5000,
            'channel' => 'cash',
            'reference_no' => 'TXN20260425AAAABBBB',
            'status' => 'completed',
            'initiated_by' => 'admin-user-1',
            'metadata' => ['source' => 'test'],
        ]);

        Transaction::query()->create([
            'account_id' => $accountId,
            'type' => 'debit',
            'amount_minor' => 1000,
            'balance_after_minor' => 4000,
            'channel' => 'internal',
            'reference_no' => 'TXN20260425CCCCDDDD',
            'status' => 'completed',
            'initiated_by' => 'admin-user-1',
            'metadata' => ['source' => 'test'],
        ]);

        $this->fakeExternalDependencies(function (HttpRequest $request) use ($accountId) {
            $url = $request->url();

            if (str_contains($url, '/api/v1/internal/auth/verify')) {
                return Http::response($this->authPayload('admin-user-1', 'admin'), 200);
            }

            if (str_ends_with($url, "/api/v1/internal/accounts/{$accountId}") && $request->method() === 'GET') {
                return Http::response($this->accountPayload($accountId, 'customer-profile-1', 4000), 200);
            }

            return Http::response([], 404);
        });

        $this->withBearerToken()->getJson("/api/v1/transactions/{$accountId}/history?type=debit&per_page=5")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.type', 'debit');
    }

    private function withBearerToken(): self
    {
        return $this->withHeader('Authorization', 'Bearer test-token');
    }

    private function fakeExternalDependencies(callable $handler): void
    {
        Http::fake($handler);
    }

    /**
     * @return array<string, mixed>
     */
    private function authPayload(string $userId, string $role): array
    {
        return [
            'success' => true,
            'data' => [
                'user' => [
                    'uuid' => $userId,
                    'email' => "{$userId}@bankcore.test",
                    'role' => $role,
                ],
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(string $customerId): array
    {
        return [
            'success' => true,
            'data' => [
                'id' => $customerId,
                'kyc_status' => 'approved',
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(string $accountId, string $customerId, int $balanceMinor): array
    {
        return [
            'success' => true,
            'data' => [
                'id' => $accountId,
                'customer_id' => $customerId,
                'status' => 'active',
                'balance_minor' => $balanceMinor,
            ],
            'meta' => ['timestamp' => now()->toIso8601String()],
        ];
    }
}

