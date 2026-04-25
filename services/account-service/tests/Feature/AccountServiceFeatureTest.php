<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountServiceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_routes_require_bearer_token(): void
    {
        $this->postJson('/api/v1/accounts', [
            'type' => 'savings',
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'TOKEN_MISSING');
    }

    public function test_approved_customer_can_open_account(): void
    {
        $this->fakeVerifiedUser('user-uuid-1', 'customer');
        $this->fakeCurrentCustomerProfile('customer-uuid-1', 'approved');

        $response = $this->withBearerToken()->postJson('/api/v1/accounts', [
            'type' => 'savings',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'savings')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.balance_minor', 0);

        $accountNumber = (string) $response->json('data.account_number');

        $this->assertMatchesRegularExpression('/^BANK\d{12}$/', $accountNumber);
        $this->assertDatabaseHas('accounts', [
            'customer_id' => 'customer-uuid-1',
            'account_number' => $accountNumber,
            'type' => 'savings',
            'status' => 'active',
            'balance_minor' => 0,
        ]);
    }

    public function test_non_kyc_customer_cannot_open_account(): void
    {
        $this->fakeVerifiedUser('user-uuid-1', 'customer');
        $this->fakeCurrentCustomerProfile('customer-uuid-1', 'pending');

        $this->withBearerToken()->postJson('/api/v1/accounts', [
            'type' => 'current',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'KYC_NOT_APPROVED');

        $this->assertDatabaseCount('accounts', 0);
    }

    public function test_customer_only_sees_their_own_accounts(): void
    {
        $this->fakeVerifiedUser('user-uuid-1', 'customer');
        $this->fakeCurrentCustomerProfile('customer-uuid-1', 'approved');

        $ownAccount = $this->createAccount([
            'customer_id' => 'customer-uuid-1',
            'account_number' => 'BANK202600000001',
        ]);

        $this->createAccount([
            'customer_id' => 'customer-uuid-2',
            'account_number' => 'BANK202600000002',
        ]);

        $this->withBearerToken()->getJson('/api/v1/accounts')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $ownAccount->id);
    }

    public function test_customer_cannot_view_another_customers_account(): void
    {
        $this->fakeVerifiedUser('user-uuid-1', 'customer');
        $this->fakeCurrentCustomerProfile('customer-uuid-1', 'approved');

        $account = $this->createAccount([
            'customer_id' => 'customer-uuid-2',
            'account_number' => 'BANK202600000010',
        ]);

        $this->withBearerToken()->getJson("/api/v1/accounts/{$account->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_officer_can_freeze_account(): void
    {
        $this->fakeVerifiedUser('officer-uuid-1', 'bank_officer');

        $account = $this->createAccount([
            'status' => 'active',
            'account_number' => 'BANK202600000020',
        ]);

        $this->withBearerToken()->patchJson("/api/v1/accounts/{$account->id}/status", [
            'status' => 'frozen',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'frozen');

        $this->assertSame('frozen', $account->fresh()->status);
    }

    public function test_customer_cannot_change_account_status(): void
    {
        $this->fakeVerifiedUser('customer-uuid-1', 'customer');
        $this->fakeCurrentCustomerProfile('customer-uuid-1', 'approved');

        $account = $this->createAccount([
            'customer_id' => 'customer-uuid-1',
            'status' => 'active',
            'account_number' => 'BANK202600000021',
        ]);

        $this->withBearerToken()->patchJson("/api/v1/accounts/{$account->id}/status", [
            'status' => 'frozen',
        ])->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_internal_adjust_balance_requires_valid_internal_token(): void
    {
        $account = $this->createAccount();

        $this->postJson("/api/v1/internal/accounts/{$account->id}/adjust-balance", [
            'delta_minor' => 1000,
        ])->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INVALID_INTERNAL_TOKEN');
    }

    public function test_internal_adjust_balance_updates_balance_for_active_account(): void
    {
        $account = $this->createAccount([
            'balance_minor' => 2000,
        ]);

        $this->withInternalToken()->postJson("/api/v1/internal/accounts/{$account->id}/adjust-balance", [
            'delta_minor' => -500,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.balance_minor', 1500);

        $this->assertSame(1500, $account->fresh()->balance_minor);
    }

    public function test_internal_adjust_balance_rejects_insufficient_balance(): void
    {
        $account = $this->createAccount([
            'balance_minor' => 400,
        ]);

        $this->withInternalToken()->postJson("/api/v1/internal/accounts/{$account->id}/adjust-balance", [
            'delta_minor' => -500,
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');

        $this->assertSame(400, $account->fresh()->balance_minor);
    }

    public function test_internal_transfer_moves_balance_between_accounts_atomically(): void
    {
        $source = $this->createAccount([
            'account_number' => 'BANK202600000120',
            'balance_minor' => 5000,
        ]);

        $destination = $this->createAccount([
            'customer_id' => 'customer-uuid-2',
            'account_number' => 'BANK202600000121',
            'balance_minor' => 1000,
        ]);

        $this->withInternalToken()->postJson('/api/v1/internal/accounts/transfer', [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 1200,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.source.balance_minor', 3800)
            ->assertJsonPath('data.destination.balance_minor', 2200);

        $this->assertSame(3800, $source->fresh()->balance_minor);
        $this->assertSame(2200, $destination->fresh()->balance_minor);
    }

    public function test_internal_transfer_rejects_insufficient_balance(): void
    {
        $source = $this->createAccount([
            'account_number' => 'BANK202600000130',
            'balance_minor' => 500,
        ]);

        $destination = $this->createAccount([
            'customer_id' => 'customer-uuid-2',
            'account_number' => 'BANK202600000131',
            'balance_minor' => 1000,
        ]);

        $this->withInternalToken()->postJson('/api/v1/internal/accounts/transfer', [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_minor' => 900,
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');

        $this->assertSame(500, $source->fresh()->balance_minor);
        $this->assertSame(1000, $destination->fresh()->balance_minor);
    }

    private function fakeVerifiedUser(string $uuid, string $role): void
    {
        Http::fake([
            '*/api/v1/internal/auth/verify' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'uuid' => $uuid,
                        'email' => "{$uuid}@bankcore.test",
                        'role' => $role,
                    ],
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 200),
        ]);
    }

    private function fakeCurrentCustomerProfile(string $customerId, string $kycStatus): void
    {
        Http::fake([
            '*/api/v1/internal/auth/verify' => Http::response([
                'success' => true,
                'data' => [
                    'user' => [
                        'uuid' => 'customer-auth-uuid',
                        'email' => 'customer@bankcore.test',
                        'role' => 'customer',
                    ],
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 200),
            '*/api/v1/customers/me' => Http::response([
                'success' => true,
                'data' => [
                    'id' => $customerId,
                    'kyc_status' => $kycStatus,
                ],
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 200),
        ]);
    }

    private function withBearerToken(): self
    {
        return $this->withHeader('Authorization', 'Bearer test-token');
    }

    private function withInternalToken(): self
    {
        return $this->withHeader('X-Internal-Token', (string) config('services.auth_service.internal_token', 'secret_key'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createAccount(array $overrides = []): Account
    {
        return Account::query()->create(array_merge([
            'customer_id' => 'customer-uuid-1',
            'account_number' => 'BANK202600000099',
            'type' => 'savings',
            'status' => 'active',
            'balance_minor' => 0,
        ], $overrides));
    }
}
