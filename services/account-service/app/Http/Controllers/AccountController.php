<?php

namespace App\Http\Controllers;

use App\Http\Requests\InternalAdjustBalanceRequest;
use App\Http\Requests\InternalTransferRequest;
use App\Http\Requests\ListAccountsRequest;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountStatusRequest;
use App\Models\Account;
use App\Services\AccountNumberGenerator;
use App\Services\CustomerProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CustomerProfileService $customerProfileService,
        private readonly AccountNumberGenerator $accountNumberGenerator,
    ) {}

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorized('Authentication token required', 'TOKEN_MISSING');
        }

        try {
            $customer = $this->customerProfileService->fetchCurrentCustomer($token);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 'CUSTOMER_SERVICE_ERROR', 503);
        }

        if (! $customer) {
            return $this->notFound('Customer profile not found', 'CUSTOMER_PROFILE_NOT_FOUND');
        }

        if ($customer['kyc_status'] !== 'approved') {
            return $this->forbidden('KYC approval is required to open an account', 'KYC_NOT_APPROVED');
        }

        $account = Account::query()->create([
            'customer_id' => $customer['id'],
            'account_number' => $this->accountNumberGenerator->generate(),
            'type' => $request->validated('type'),
            'status' => 'active',
            'balance_minor' => 0,
        ]);

        return $this->created($account, 'Account created successfully');
    }

    public function index(ListAccountsRequest $request): JsonResponse
    {
        $role = (string) $request->attributes->get('user_role', '');
        $query = Account::query()->orderByDesc('created_at');

        if ($role === 'customer') {
            $token = $request->bearerToken();

            if (! $token) {
                return $this->unauthorized('Authentication token required', 'TOKEN_MISSING');
            }

            try {
                $customer = $this->customerProfileService->fetchCurrentCustomer($token);
            } catch (RuntimeException $exception) {
                return $this->error($exception->getMessage(), 'CUSTOMER_SERVICE_ERROR', 503);
            }

            if (! $customer) {
                return $this->notFound('Customer profile not found', 'CUSTOMER_PROFILE_NOT_FOUND');
            }

            $query->where('customer_id', $customer['id']);
        } else {
            $filterCustomerId = $request->validated('customer_id');
            if ($filterCustomerId) {
                $query->where('customer_id', $filterCustomerId);
            }
        }

        $statusFilter = $request->validated('status');
        if ($statusFilter) {
            $query->where('status', $statusFilter);
        }

        $accounts = $query->paginate((int) $request->validated('per_page', 15));

        return $this->success($accounts, 'Accounts retrieved successfully');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $account = Account::query()->find($id);

        if (! $account) {
            return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
        }

        $ownershipError = $this->ensureCustomerCanAccessAccount($request, $account);
        if ($ownershipError) {
            return $ownershipError;
        }

        return $this->success($account, 'Account details retrieved successfully');
    }

    public function balance(Request $request, string $id): JsonResponse
    {
        $account = Account::query()->find($id);

        if (! $account) {
            return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
        }

        $ownershipError = $this->ensureCustomerCanAccessAccount($request, $account);
        if ($ownershipError) {
            return $ownershipError;
        }

        return $this->success([
            'account_id' => $account->id,
            'account_number' => $account->account_number,
            'balance_minor' => $account->balance_minor,
            'currency' => 'INR',
        ], 'Account balance retrieved successfully');
    }

    public function updateStatus(UpdateAccountStatusRequest $request, string $id): JsonResponse
    {
        $account = Account::query()->find($id);

        if (! $account) {
            return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
        }

        $newStatus = $request->validated('status');

        if ($account->status === 'closed' && $newStatus !== 'closed') {
            return $this->error(
                'Closed accounts cannot be reactivated',
                'ACCOUNT_CLOSED_IMMUTABLE',
                409,
            );
        }

        if ($account->status === $newStatus) {
            return $this->success($account, 'Account status unchanged');
        }

        $account->status = $newStatus;
        $account->save();

        return $this->success($account, 'Account status updated successfully');
    }

    public function internalShow(string $id): JsonResponse
    {
        $account = Account::query()->find($id);

        if (! $account) {
            return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
        }

        return $this->success([
            'id' => $account->id,
            'customer_id' => $account->customer_id,
            'account_number' => $account->account_number,
            'status' => $account->status,
            'balance_minor' => $account->balance_minor,
            'currency' => 'INR',
        ], 'Internal account details retrieved successfully');
    }

    public function internalAdjustBalance(InternalAdjustBalanceRequest $request, string $id): JsonResponse
    {
        $deltaMinor = (int) $request->validated('delta_minor');

        return DB::transaction(function () use ($id, $deltaMinor): JsonResponse {
            $account = Account::query()->lockForUpdate()->find($id);

            if (! $account) {
                return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
            }

            if ($account->status !== 'active') {
                return $this->error(
                    'Only active accounts can process monetary operations',
                    'ACCOUNT_NOT_ACTIVE',
                    409,
                );
            }

            $newBalance = $account->balance_minor + $deltaMinor;

            if ($newBalance < 0) {
                return $this->error(
                    'Insufficient balance',
                    'INSUFFICIENT_BALANCE',
                    422,
                    [
                        'available_balance_minor' => $account->balance_minor,
                        'requested_delta_minor' => $deltaMinor,
                    ],
                );
            }

            $account->balance_minor = $newBalance;
            $account->save();

            return $this->success([
                'account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'balance_minor' => $account->balance_minor,
                'currency' => 'INR',
            ], 'Balance adjusted successfully');
        });
    }

    public function internalTransfer(InternalTransferRequest $request): JsonResponse
    {
        $sourceAccountId = (string) $request->validated('source_account_id');
        $destinationAccountId = (string) $request->validated('destination_account_id');
        $amountMinor = (int) $request->validated('amount_minor');

        return DB::transaction(function () use ($sourceAccountId, $destinationAccountId, $amountMinor): JsonResponse {
            $ids = [$sourceAccountId, $destinationAccountId];
            sort($ids);

            $accounts = Account::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $source = $accounts->get($sourceAccountId);
            $destination = $accounts->get($destinationAccountId);

            if (! $source || ! $destination) {
                return $this->notFound('Source or destination account not found', 'ACCOUNT_NOT_FOUND');
            }

            if ($source->status !== 'active' || $destination->status !== 'active') {
                return $this->error(
                    'Both source and destination accounts must be active',
                    'ACCOUNT_NOT_ACTIVE',
                    409,
                );
            }

            if ($source->balance_minor < $amountMinor) {
                return $this->error(
                    'Insufficient balance',
                    'INSUFFICIENT_BALANCE',
                    422,
                    [
                        'available_balance_minor' => $source->balance_minor,
                        'requested_amount_minor' => $amountMinor,
                    ],
                );
            }

            $source->balance_minor -= $amountMinor;
            $destination->balance_minor += $amountMinor;

            $source->save();
            $destination->save();

            return $this->success([
                'source' => [
                    'account_id' => $source->id,
                    'customer_id' => $source->customer_id,
                    'balance_minor' => $source->balance_minor,
                    'currency' => 'INR',
                ],
                'destination' => [
                    'account_id' => $destination->id,
                    'customer_id' => $destination->customer_id,
                    'balance_minor' => $destination->balance_minor,
                    'currency' => 'INR',
                ],
            ], 'Transfer applied successfully');
        });
    }

    private function ensureCustomerCanAccessAccount(Request $request, Account $account): ?JsonResponse
    {
        $role = (string) $request->attributes->get('user_role', '');
        if ($role !== 'customer') {
            return null;
        }

        $token = $request->bearerToken();
        if (! $token) {
            return $this->unauthorized('Authentication token required', 'TOKEN_MISSING');
        }

        try {
            $customer = $this->customerProfileService->fetchCurrentCustomer($token);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 'CUSTOMER_SERVICE_ERROR', 503);
        }

        if (! $customer) {
            return $this->notFound('Customer profile not found', 'CUSTOMER_PROFILE_NOT_FOUND');
        }

        if ($account->customer_id !== $customer['id']) {
            return $this->forbidden('You cannot access another customer account', 'FORBIDDEN');
        }

        return null;
    }
}
