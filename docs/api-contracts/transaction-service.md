# Transaction Service Contracts

## Health
- `GET /api/v1/health`

## Authentication

All transaction endpoints require a bearer access token. Transaction Service verifies
the token with Auth Service via `GET /api/v1/internal/auth/verify`.

Role rules:
- `customer` can transact only on their own source account.
- `admin` and `bank_officer` can transact on any account.

## Endpoints

- `POST /api/v1/transactions/deposit` - credit account and write immutable ledger row.
- `POST /api/v1/transactions/withdraw` - debit account (insufficient balance guarded).
- `POST /api/v1/transactions/transfer` - atomic source debit + destination credit through account-service internal API.
- `GET /api/v1/transactions/{accountId}/history` - paginated and filterable account ledger history.

## Request Payloads

### Deposit

```json
{
  "account_id": "uuid",
  "amount_minor": 100000,
  "channel": "cash",
  "description": "optional",
  "metadata": {}
}
```

### Withdraw

```json
{
  "account_id": "uuid",
  "amount_minor": 30000,
  "channel": "internal",
  "description": "optional",
  "metadata": {}
}
```

### Transfer

```json
{
  "source_account_id": "uuid",
  "destination_account_id": "uuid",
  "amount_minor": 20000,
  "channel": "internal",
  "description": "optional",
  "metadata": {}
}
```

### History Filters

- `per_page` (1-100)
- `type` (`credit` | `debit`)
- `status` (`pending` | `completed` | `failed` | `reversed`)
- `channel` (`cash` | `upi` | `neft` | `imps` | `internal` | `razorpay`)
- `from_date`, `to_date`

## Ledger Guarantees

- No update/delete routes exist for transaction records.
- Every successful money movement writes immutable ledger entries.
- Transfer writes one debit and one credit entry linked by `transfer_group_id`.
- Every completed movement writes `transaction_audit_logs` entries.

## Error Codes

- `TOKEN_MISSING` - bearer token not supplied.
- `AUTHENTICATION_FAILED` - auth verification failed.
- `FORBIDDEN` - ownership or role violation.
- `ACCOUNT_NOT_FOUND` - account id not found.
- `ACCOUNT_NOT_ACTIVE` - attempted monetary operation on frozen/closed account.
- `INSUFFICIENT_BALANCE` - debit/withdraw/transfer exceeded available balance.
- `ACCOUNT_SERVICE_UNAVAILABLE` - account-service unreachable.
- `CUSTOMER_SERVICE_UNAVAILABLE` - customer-service unreachable.
- `VALIDATION_ERROR` - request validation failed.
