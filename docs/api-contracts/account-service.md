# Account Service Contracts

## Health

- `GET /api/v1/health`

## Authentication

All account endpoints require a bearer access token. Account Service verifies
the token with Auth Service via `GET /api/v1/internal/auth/verify`.

Role rules:
- `customer` can create accounts and access only their own accounts.
- `admin` and `bank_officer` can list/filter any account and change account status.

## Endpoints

- `POST /api/v1/accounts` - create a new account for the authenticated customer.
- `GET /api/v1/accounts` - list accounts:
  - customer: returns only the caller's accounts
  - admin/officer: supports optional filters (`customer_id`, `status`)
- `GET /api/v1/accounts/{id}` - account details with ownership enforcement.
- `GET /api/v1/accounts/{id}/balance` - account balance payload.
- `PATCH /api/v1/accounts/{id}/status` - officer/admin only; status transitions.

## Internal Endpoints (Service-to-Service)

These endpoints are protected by `X-Internal-Token` and are intended for
trusted internal services only.

- `GET /api/v1/internal/accounts/{id}` - fetch internal account snapshot.
- `POST /api/v1/internal/accounts/{id}/adjust-balance` - atomic balance delta (`delta_minor`).
- `POST /api/v1/internal/accounts/transfer` - atomic source->destination balance movement.

## Account Creation Rules

- Request body:
  - `type` required, one of: `savings`, `current`
- KYC guard:
  - account creation calls Customer Service `GET /api/v1/customers/me`
  - KYC must be `approved`
- Account number format:
  - `BANKYYYY########` (8-digit yearly sequence)
  - unique across the service

## Account Status

Valid statuses:
- `active`
- `frozen`
- `closed`

Rules:
- closed account is immutable (cannot be moved back to active/frozen).

## Error Codes

- `TOKEN_MISSING` - bearer token not supplied.
- `AUTHENTICATION_FAILED` - auth verification failed.
- `FORBIDDEN` - role or ownership violation.
- `KYC_NOT_APPROVED` - customer KYC is not approved.
- `CUSTOMER_PROFILE_NOT_FOUND` - no customer profile in customer-service.
- `CUSTOMER_SERVICE_ERROR` - customer-service unavailable or invalid response.
- `ACCOUNT_NOT_FOUND` - account id does not exist.
- `ACCOUNT_CLOSED_IMMUTABLE` - attempted to reactivate a closed account.
- `INVALID_INTERNAL_TOKEN` - missing/invalid `X-Internal-Token` on internal routes.
- `ACCOUNT_NOT_ACTIVE` - monetary operation attempted on non-active account.
- `INSUFFICIENT_BALANCE` - debit request exceeded available balance.
- `VALIDATION_ERROR` - request validation failed.
