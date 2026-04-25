<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionLedgerService
{
    public function __construct(
        private readonly AccountServiceClient $accountServiceClient,
        private readonly TransactionReferenceGenerator $referenceGenerator,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function deposit(
        string $accountId,
        int $amountMinor,
        string $initiatedBy,
        string $initiatorRole,
        string $channel = 'internal',
        ?string $description = null,
        array $metadata = [],
    ): Transaction {
        $accountSnapshot = $this->accountServiceClient->adjustBalance($accountId, $amountMinor);

        return DB::transaction(function () use (
            $accountId,
            $amountMinor,
            $accountSnapshot,
            $initiatedBy,
            $initiatorRole,
            $channel,
            $description,
            $metadata,
        ): Transaction {
            $transaction = $this->createTransaction(
                accountId: $accountId,
                type: 'credit',
                amountMinor: $amountMinor,
                balanceAfterMinor: $accountSnapshot['balance_minor'],
                initiatedBy: $initiatedBy,
                channel: $channel,
                description: $description,
                metadata: $metadata,
            );

            $this->writeAuditLog(
                transactionId: $transaction->id,
                action: 'deposit_completed',
                actorId: $initiatedBy,
                actorRole: $initiatorRole,
                accountId: $accountId,
                payload: [
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $accountSnapshot['balance_minor'],
                    'channel' => $channel,
                ],
            );

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withdraw(
        string $accountId,
        int $amountMinor,
        string $initiatedBy,
        string $initiatorRole,
        string $channel = 'internal',
        ?string $description = null,
        array $metadata = [],
    ): Transaction {
        $accountSnapshot = $this->accountServiceClient->adjustBalance($accountId, -$amountMinor);

        return DB::transaction(function () use (
            $accountId,
            $amountMinor,
            $accountSnapshot,
            $initiatedBy,
            $initiatorRole,
            $channel,
            $description,
            $metadata,
        ): Transaction {
            $transaction = $this->createTransaction(
                accountId: $accountId,
                type: 'debit',
                amountMinor: $amountMinor,
                balanceAfterMinor: $accountSnapshot['balance_minor'],
                initiatedBy: $initiatedBy,
                channel: $channel,
                description: $description,
                metadata: $metadata,
            );

            $this->writeAuditLog(
                transactionId: $transaction->id,
                action: 'withdrawal_completed',
                actorId: $initiatedBy,
                actorRole: $initiatorRole,
                accountId: $accountId,
                payload: [
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $accountSnapshot['balance_minor'],
                    'channel' => $channel,
                ],
            );

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{debit: Transaction, credit: Transaction}
     */
    public function transfer(
        string $sourceAccountId,
        string $destinationAccountId,
        int $amountMinor,
        string $initiatedBy,
        string $initiatorRole,
        string $channel = 'internal',
        ?string $description = null,
        array $metadata = [],
    ): array {
        $snapshot = $this->accountServiceClient->transfer($sourceAccountId, $destinationAccountId, $amountMinor);
        $transferGroupId = (string) Str::uuid();

        return DB::transaction(function () use (
            $sourceAccountId,
            $destinationAccountId,
            $amountMinor,
            $initiatedBy,
            $initiatorRole,
            $channel,
            $description,
            $metadata,
            $snapshot,
            $transferGroupId,
        ): array {
            $debit = $this->createTransaction(
                accountId: $sourceAccountId,
                type: 'debit',
                amountMinor: $amountMinor,
                balanceAfterMinor: $snapshot['source']['balance_minor'],
                initiatedBy: $initiatedBy,
                channel: $channel,
                description: $description,
                metadata: array_merge($metadata, [
                    'direction' => 'outgoing',
                    'counterparty_account_id' => $destinationAccountId,
                ]),
                transferGroupId: $transferGroupId,
            );

            $credit = $this->createTransaction(
                accountId: $destinationAccountId,
                type: 'credit',
                amountMinor: $amountMinor,
                balanceAfterMinor: $snapshot['destination']['balance_minor'],
                initiatedBy: $initiatedBy,
                channel: $channel,
                description: $description,
                metadata: array_merge($metadata, [
                    'direction' => 'incoming',
                    'counterparty_account_id' => $sourceAccountId,
                ]),
                transferGroupId: $transferGroupId,
                relatedTransactionId: $debit->id,
            );

            $this->writeAuditLog(
                transactionId: $debit->id,
                action: 'transfer_debit_completed',
                actorId: $initiatedBy,
                actorRole: $initiatorRole,
                accountId: $sourceAccountId,
                payload: [
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $snapshot['source']['balance_minor'],
                    'transfer_group_id' => $transferGroupId,
                    'counterparty_account_id' => $destinationAccountId,
                ],
            );

            $this->writeAuditLog(
                transactionId: $credit->id,
                action: 'transfer_credit_completed',
                actorId: $initiatedBy,
                actorRole: $initiatorRole,
                accountId: $destinationAccountId,
                payload: [
                    'amount_minor' => $amountMinor,
                    'balance_after_minor' => $snapshot['destination']['balance_minor'],
                    'transfer_group_id' => $transferGroupId,
                    'counterparty_account_id' => $sourceAccountId,
                ],
            );

            return [
                'debit' => $debit,
                'credit' => $credit,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createTransaction(
        string $accountId,
        string $type,
        int $amountMinor,
        int $balanceAfterMinor,
        string $initiatedBy,
        string $channel,
        ?string $description,
        array $metadata = [],
        ?string $relatedTransactionId = null,
        ?string $transferGroupId = null,
    ): Transaction {
        return Transaction::query()->create([
            'account_id' => $accountId,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'balance_after_minor' => $balanceAfterMinor,
            'channel' => $channel,
            'reference_no' => $this->referenceGenerator->generate(),
            'description' => $description,
            'status' => 'completed',
            'initiated_by' => $initiatedBy,
            'related_transaction_id' => $relatedTransactionId,
            'transfer_group_id' => $transferGroupId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeAuditLog(
        string $transactionId,
        string $action,
        string $actorId,
        string $actorRole,
        string $accountId,
        array $payload = [],
    ): void {
        TransactionAuditLog::query()->create([
            'transaction_id' => $transactionId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'account_id' => $accountId,
            'payload' => $payload,
        ]);
    }
}

