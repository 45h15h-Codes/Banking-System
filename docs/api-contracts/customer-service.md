# Customer Service Contracts

## Health

- `GET /api/v1/health`

## Authentication

All customer and KYC endpoints require a bearer access token. Customer Service
verifies the token through Auth Service's internal verification endpoint and
uses the returned `uuid` and `role` for authorization.

## Customer Profile

- `POST /api/v1/customers` - create the authenticated customer's profile.
- `GET /api/v1/customers/me` - get the authenticated customer's profile,
  including KYC documents and audit entries.
- `PUT /api/v1/customers/me` - update mutable profile fields.
- `GET /api/v1/customers/{id}/kyc/status` - get KYC status. Customers may only
  access their own status; `admin` and `bank_officer` may access any customer.

Sensitive identifiers (`pan_number`, `aadhaar_number`) are encrypted at rest
and are not returned in API payloads.

## KYC Documents

- `POST /api/v1/kyc/documents` - upload one KYC document for the authenticated
  customer. Accepted types: `pan`, `aadhaar`, `passport`, `utility_bill`,
  `driving_license`. Accepted file formats: `pdf`, `jpg`, `jpeg`, `png`, max
  `5120 KB`.
- `POST /api/v1/kyc/submit` - submit or resubmit KYC for officer review.

## Officer/Admin KYC Review

These endpoints require `admin` or `bank_officer` role.

- `GET /api/v1/kyc/reviews?status=under_review&per_page=50` - list customers in
  a review queue. `status` may be `pending`, `under_review`, `approved`, or
  `rejected`; `per_page` is capped at 100.
- `GET /api/v1/kyc/reviews/{id}` - get a single KYC review record.
- `PATCH /api/v1/kyc/{id}/status` - approve or reject KYC. Only customers in
  `under_review` status can be approved or rejected.

## KYC Status Transitions

- Customer submit: `pending -> under_review`
- Customer resubmit: `rejected -> under_review`
- Officer/admin decision: `under_review -> approved`
- Officer/admin decision: `under_review -> rejected`

Every status transition writes an immutable `kyc_audit_logs` record.
