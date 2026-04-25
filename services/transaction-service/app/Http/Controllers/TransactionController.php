<?php

namespace App\Http\Controllers;

use App\Exceptions\ServiceClientException;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\TransactionHistoryRequest;
use App\Http\Requests\TransferRequest;
use App\Http\Requests\WithdrawRequest;
use App\Models\Transaction;
use App\Services\AccountServiceClient;
use App\Services\CustomerProfileService;
use App\Services\TransactionLedgerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TransactionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TransactionLedgerService $transactionLedgerService,
        private readonly AccountServiceClient $accountServiceClient,
        private readonly CustomerProfileService $customerProfileService,
    ) {}

    public function deposit(DepositRequest $request): JsonResponse
    {
        try {
            $accountId = (string) $request->validated('account_id');
            $account = $this->accountServiceClient->fetchAccount($accountId);

            if (! $account) {
                return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
            }

            $ownershipError = $this->ensureCustomerCanAccessAccount($request, $account);
            if ($ownershipError) {
                return $ownershipError;
            }

            $transaction = $this->transactionLedgerService->deposit(
                accountId: $accountId,
                amountMinor: (int) $request->validated('amount_minor'),
                initiatedBy: (string) $request->attributes->get('user_id', ''),
                initiatorRole: (string) $request->attributes->get('user_role', ''),
                channel: (string) $request->validated('channel', 'internal'),
                description: $request->validated('description'),
                metadata: (array) $request->validated('metadata', []),
            );

            return $this->created($transaction, 'Deposit completed successfully');
        } catch (ServiceClientException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->errorCode(),
                $exception->status(),
                $exception->details(),
            );
        }
    }

    public function withdraw(WithdrawRequest $request): JsonResponse
    {
        try {
            $accountId = (string) $request->validated('account_id');
            $account = $this->accountServiceClient->fetchAccount($accountId);

            if (! $account) {
                return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
            }

            $ownershipError = $this->ensureCustomerCanAccessAccount($request, $account);
            if ($ownershipError) {
                return $ownershipError;
            }

            $transaction = $this->transactionLedgerService->withdraw(
                accountId: $accountId,
                amountMinor: (int) $request->validated('amount_minor'),
                initiatedBy: (string) $request->attributes->get('user_id', ''),
                initiatorRole: (string) $request->attributes->get('user_role', ''),
                channel: (string) $request->validated('channel', 'internal'),
                description: $request->validated('description'),
                metadata: (array) $request->validated('metadata', []),
            );

            return $this->created($transaction, 'Withdrawal completed successfully');
        } catch (ServiceClientException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->errorCode(),
                $exception->status(),
                $exception->details(),
            );
        }
    }

    public function transfer(TransferRequest $request): JsonResponse
    {
        try {
            $sourceAccountId = (string) $request->validated('source_account_id');
            $sourceAccount = $this->accountServiceClient->fetchAccount($sourceAccountId);

            if (! $sourceAccount) {
                return $this->notFound('Source account not found', 'ACCOUNT_NOT_FOUND');
            }

            $ownershipError = $this->ensureCustomerCanAccessAccount($request, $sourceAccount);
            if ($ownershipError) {
                return $ownershipError;
            }

            $destinationAccountId = (string) $request->validated('destination_account_id');
            $destinationAccount = $this->accountServiceClient->fetchAccount($destinationAccountId);
            if (! $destinationAccount) {
                return $this->notFound('Destination account not found', 'ACCOUNT_NOT_FOUND');
            }

            $transactions = $this->transactionLedgerService->transfer(
                sourceAccountId: $sourceAccountId,
                destinationAccountId: $destinationAccountId,
                amountMinor: (int) $request->validated('amount_minor'),
                initiatedBy: (string) $request->attributes->get('user_id', ''),
                initiatorRole: (string) $request->attributes->get('user_role', ''),
                channel: (string) $request->validated('channel', 'internal'),
                description: $request->validated('description'),
                metadata: (array) $request->validated('metadata', []),
            );

            return $this->created([
                'debit' => $transactions['debit'],
                'credit' => $transactions['credit'],
            ], 'Transfer completed successfully');
        } catch (ServiceClientException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->errorCode(),
                $exception->status(),
                $exception->details(),
            );
        }
    }

    public function history(TransactionHistoryRequest $request, string $accountId): JsonResponse
    {
        try {
            $account = $this->accountServiceClient->fetchAccount($accountId);
            if (! $account) {
                return $this->notFound('Account not found', 'ACCOUNT_NOT_FOUND');
            }

            $ownershipError = $this->ensureCustomerCanAccessAccount($request, $account);
            if ($ownershipError) {
                return $ownershipError;
            }
        } catch (ServiceClientException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->errorCode(),
                $exception->status(),
                $exception->details(),
            );
        }

        $query = Transaction::query()
            ->where('account_id', $accountId)
            ->orderByDesc('created_at');

        $type = $request->validated('type');
        if ($type) {
            $query->where('type', $type);
        }

        $status = $request->validated('status');
        if ($status) {
            $query->where('status', $status);
        }

        $channel = $request->validated('channel');
        if ($channel) {
            $query->where('channel', $channel);
        }

        $fromDate = $request->validated('from_date');
        if ($fromDate) {
            $query->where('created_at', '>=', Carbon::parse($fromDate)->startOfDay());
        }

        $toDate = $request->validated('to_date');
        if ($toDate) {
            $query->where('created_at', '<=', Carbon::parse($toDate)->endOfDay());
        }

        $history = $query->paginate((int) $request->validated('per_page', 15));

        return $this->success($history, 'Transaction history retrieved successfully');
    }

    /**
     * @param  array{id: string, customer_id: string, status: string, balance_minor: int}  $account
     */
    private function ensureCustomerCanAccessAccount(Request $request, array $account): ?JsonResponse
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
        } catch (ServiceClientException $exception) {
            return $this->error(
                $exception->getMessage(),
                $exception->errorCode(),
                $exception->status(),
                $exception->details(),
            );
        }

        if (! $customer) {
            return $this->notFound('Customer profile not found', 'CUSTOMER_PROFILE_NOT_FOUND');
        }

        if ($account['customer_id'] !== $customer['id']) {
            return $this->forbidden('You cannot access another customer account', 'FORBIDDEN');
        }

        return null;
    }
}

