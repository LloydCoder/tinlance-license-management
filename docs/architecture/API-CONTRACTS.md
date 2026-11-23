# TLMS API contracts — v1 baseline

**Status:** Contract baseline with implemented identity-management operations. Membership role assignment/revocation and tenant-admin vendor/customer membership revocation endpoints are implemented under `routes/web.php` and specified in `docs/api/openapi.yaml`; remaining endpoint groups are contracts only until each receives policy, service and test coverage.

## 1. API principles

- Base path: `/api/v1`.
- JSON request/response by default; UTF-8.
- OpenAPI 3.1.1 contract-first. Breaking changes require a new major API version; additive optional fields are preferred within v1.
- Use HTTPS only. Never expose credentials in query strings.
- Admin browser APIs use secure session authentication, CSRF protection and server-side policies.
- Machine APIs use scoped, revocable, hashed API tokens or product/install-bound credentials. Never use an admin session token in an SDK.
- Payment webhooks use provider-specific signature verification and event idempotency, not generic bearer auth.
- Errors use `application/problem+json` following RFC 9457, with a stable TLMS error code extension and correlation ID. Never include secrets, stack traces or internal SQL.
- List endpoints use bounded pagination; filters and sorting are allow-listed.
- Mutating requests that may be retried accept an `Idempotency-Key`; server stores scope, key, request digest, status and response reference.
- Dates are RFC 3339 UTC timestamps; currency amounts are integer minor units with ISO 4217 currency code. Crypto uses integer base units plus chain/token identity.
- Every response includes or is correlated with a request ID. Do not return private internal identifiers unless needed by the caller.

## 2. Authentication profiles

| Profile | Consumers | Credential | Scope |
|---|---|---|---|
| Admin session | Vendor admins and customer portal users | Secure HttpOnly session cookie + CSRF token | Human role + resource policies |
| Admin API token | Approved automation | Hashed opaque token, scopes, expiry, revocation | Explicit actor and vendor scope |
| SDK installation | Customer software server | Product/install-bound public identifier + challenge proof or scoped token | Activation, verification, entitlement and update check only |
| Provider webhook | Payment/email/storage provider | Provider-specific HMAC/asymmetric signature verification | One provider/environment and event allow-list |
| Internal system | Cron/outbox jobs | Server-owned system principal | Single named job capability |

## 3. Core endpoint groups

| Method | Path | Purpose | Auth |
|---|---|---|---|
| POST | /auth/login | Human sign-in | Public, throttled |
| POST | /auth/logout | End session | Admin session + CSRF |
| POST | /auth/mfa/challenge | Complete MFA challenge | Pending authenticated session |
| GET | /me | Current actor and permission summary | Admin session/token |
| POST | /security/step-up/totp | Verify a fresh TOTP factor and establish a five-minute session step-up marker; recovery codes are not accepted | Verified current session + recent password confirmation + enrolled TOTP + rate limit |
| POST | /vendor-accounts/{vendorId}/memberships/{membershipId}/revoke | Revoke invited/active vendor membership; append immutable lifecycle evidence | Verified vendor session + current session + password confirmation + enrolled MFA + fresh step-up + admins.manage |
| POST | /vendor-accounts/{vendorId}/memberships/{membershipId}/roles/{roleId} | Assign allowlisted vendor membership role with immutable reason event | Verified vendor session + current session + password confirmation + MFA + admins.manage |
| POST | /vendor-accounts/{vendorId}/memberships/{membershipId}/roles/{roleId}/revoke | Revoke vendor membership role with immutable reason event | Same as assignment |
| POST | /customer-organizations/{organizationId}/memberships/{membershipId}/revoke | Revoke invited/active customer membership; append immutable lifecycle evidence | Verified customer session + current session + password confirmation + enrolled MFA + fresh step-up + customer.members.manage |
| POST | /customer-organizations/{organizationId}/memberships/{membershipId}/roles/{roleId} | Assign allowlisted customer membership role with immutable reason event | Verified customer session + current session + password confirmation + MFA + customer.members.manage |
| POST | /customer-organizations/{organizationId}/memberships/{membershipId}/roles/{roleId}/revoke | Revoke customer membership role with immutable reason event | Same as assignment |
| GET/POST | /admin/integrations/{provider}/credentials | Read metadata / stage credential version | Super Admin |
| POST | /admin/integrations/{provider}/credentials/{id}/test | Test staged credential | Super Admin + step-up |
| POST | /admin/integrations/{provider}/credentials/{id}/activate | Activate validated version | Super Admin + step-up |
| POST | /admin/integrations/{provider}/credentials/{id}/disable | Disable active version | Super Admin + step-up |
| GET/POST | /products | List/create products | Product permissions |
| GET/PATCH | /products/{productId} | Read/update product | Product permissions |
| GET/POST | /products/{productId}/plans | List/create plan versions | Plan permissions |
| POST | /licences | Issue licence | licences.issue + payment/grant policy |
| GET | /licences/{licenceId} | Read licence | Scoped licence read |
| POST | /licences/{licenceId}/activations | Activate installation | SDK principal |
| POST | /activations/verify | Verify signed lease / current state | SDK principal |
| POST | /activations/{activationId}/deactivate | Deactivate installation | SDK principal / authorized admin |
| GET | /products/{productId}/updates/latest | Get eligible release metadata | SDK principal |
| POST | /payments/intents | Create payment intent | Customer/order context |
| POST | /webhooks/payments/{provider} | Receive provider event | Provider signature |
| GET | /invoices/{invoiceId} | Read invoice | Finance/customer billing scope |
| POST | /invoices/{invoiceId}/refunds | Initiate refund | Finance + maker-checker policy |
| GET | /admin/audit-events | Search audit history | audit.read |
| GET | /health/live | Process liveness | Public/minimal |
| GET | /health/ready | Dependency readiness | Restricted/monitor token |

This is an initial resource map. The four membership-role operations and two tenant-admin membership revocation operations above are implemented; all other operations remain contract-only until their concrete schema, policy, service and tests are implemented.

## 4. Common request/response conventions

### Success envelope

Use resource representations directly, with stable identifiers and explicit links only where useful. Collection response:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 25,
    "total": 0
  },
  "request_id": "req_01J..."
}
```

### Problem response

```json
{
  "type": "https://docs.tinlance.com/tlms/problems/validation-failed",
  "title": "Request validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "instance": "/api/v1/licences",
  "code": "VALIDATION_FAILED",
  "request_id": "req_01J...",
  "errors": {
    "product_id": ["The selected product is unavailable."]
  }
}
```

Never expose exception messages from providers, database errors, stack traces, credential values or signing internals.

## 5. Idempotency and concurrency

- Idempotency keys are scoped to actor/provider or vendor + operation; store a request digest and result for a documented retention period.
- Reuse of a key with a different request digest returns a conflict.
- Licence issue, activation, payment event ingestion, refund and outbox delivery are idempotent.
- Use database transactions and unique constraints for seat/activation allocation; do not rely on a prior count query alone.
- State changes include expected resource version or use conditional updates; stale writes return 409.
- Provider webhook event IDs are unique per provider/environment/account.
- Never trust provider redirect success pages as payment confirmation.

## 6. SDK activation contract

1. SDK submits product public identifier, licence key, normalized domain, stable installation ID, SDK version and a fresh request nonce.
2. Server applies rate limits and verifies the key using a keyed verifier, product status, licence state, term, entitlement snapshot and activation policy.
3. Server atomically allocates or recognizes the installation. Duplicate retry with same idempotency context returns the same logical result.
4. Server returns a signed, versioned lease containing issuer, audience, licence/product reference, installation binding, entitlement version, issued-at, expiry and key ID. Do not include customer PII or provider secrets.
5. SDK validates the signature locally using trusted public keys and enforces expiry and documented grace behavior.
6. Verification refresh may return a newer signed lease or a structured denial. A central outage does not extend the lease indefinitely.
7. Deactivation/transfer/recovery requires proof of installation control or an authorized admin workflow; do not trust a caller-supplied installation ID alone.

## 7. Payment webhook contract

- Verify raw request bytes against provider-specific signature scheme before parsing or acting.
- Validate timestamp/replay window where provider supports it.
- Persist a minimal immutable provider event record and signature outcome.
- Enforce unique provider event ID and idempotent processing.
- Resolve order/payment intent from server-side references; compare amount, currency, merchant account, environment and expected status.
- A valid signature alone does not prove an event is for the correct TLMS order.
- Commit ledger allocation and entitlement issuance atomically or via transactional outbox.
- Return provider-compatible acknowledgement only after durable acceptance; process recoverably.
- Reconcile against provider API/settlement reports; handle duplicate, delayed, out-of-order, refunded and chargeback events.

## 8. API security requirements

- Object-level authorization on every object endpoint.
- Function-level authorization on every administrative operation.
- Rate limits per IP, actor, token, installation, provider and sensitive operation.
- Strict content type, payload size, field allow-list and schema validation.
- No mass assignment of role, vendor scope, price, payment status, entitlement or audit fields.
- Prevent SSRF in provider adapters and update URL handling.
- CORS allow-list; no wildcard credentialed origins.
- CSRF for cookie-authenticated browser mutations.
- TLS, secure headers, request correlation and redacted logs.
- Deprecation and sunset policy with SDK compatibility matrix.
- OpenAPI contract validation in CI; contract tests for adapters and SDK.

## 9. Versioning and compatibility

- API path version major: `/api/v1`.
- SDK has independent semantic version and tested API compatibility range.
- Signed licence and update metadata have explicit schema version and key ID.
- Additive fields are optional for old clients; unknown critical security claims cause safe rejection.
- Breaking changes require migration window, deprecation notice and compatibility tests.

## 10. Normative protocol references

- [OpenAPI Specification 3.1.1](https://spec.openapis.org/oas/v3.1.1.html) — machine-readable HTTP API contract.
- [RFC 9457 — Problem Details for HTTP APIs](https://www.rfc-editor.org/rfc/rfc9457.html) — interoperable error representation.
- [RFC 9110 — HTTP Semantics](https://www.rfc-editor.org/rfc/rfc9110.html) — method and status semantics.
- [RFC 8785 — JSON Canonicalization Scheme](https://www.rfc-editor.org/rfc/rfc8785.html) — canonical serialization for signed JSON claims where applicable.

The OpenAPI document is a first contract draft and must pass schema validation, security review and SDK contract tests before implementation is considered conformant.

## 10. Normative protocol references

- [OpenAPI Specification 3.1.1](https://spec.openapis.org/oas/v3.1.1.html) — machine-readable HTTP API contract.
- [RFC 9457 — Problem Details for HTTP APIs](https://www.rfc-editor.org/rfc/rfc9457.html) — interoperable error representation.
- [RFC 9110 — HTTP Semantics](https://www.rfc-editor.org/rfc/rfc9110.html) — method and status semantics.
- [RFC 8785 — JSON Canonicalization Scheme](https://www.rfc-editor.org/rfc/rfc8785.html) — canonical serialization for signed JSON claims where applicable.

The OpenAPI document is a first contract draft and must pass schema validation, security review and SDK contract tests before implementation is considered conformant.


## Product catalogue endpoints (PR #98)

- GET /api/v1/products lists products in the active vendor scope. Product managers see their created products; vendor Super Admins see all vendor products.
- POST /api/v1/products creates a draft product. It requires recent password confirmation, fresh MFA step-up, name, slug and a reason.
- GET /api/v1/products/{productId} returns only a product visible to the caller; out-of-scope product IDs are concealed with 404.
- PATCH /api/v1/products/{productId} updates draft name, slug and description with expected_version. Stale versions return 409; server-owned identity, tenant, creator and lifecycle fields are prohibited.
- Product writes append immutable product_events and a version-2 platform audit event in the same transaction.
- POST /api/v1/products/{productId}/assignments grants an assignment to an active same-vendor membership with products.read or products.manage. Only a non-bootstrap vendor Super Admin may grant assignments; recent password confirmation and fresh MFA step-up are mandatory. Repeated active grants return 200 without duplicate evidence.
- DELETE /api/v1/products/{productId}/assignments/{membershipId} revokes the assignment with a required reason and fresh MFA step-up. Repeated revocation returns 204 without duplicate evidence.
- Product creators receive an initial assignment. Product managers and developers can list/read only products actively assigned to their vendor membership; developers are read-only. Product managers can update assigned draft products. Vendor Super Admins can view/manage all products in their vendor account.
- Assignment state is mutable only through grant/revoke transitions; every transition creates an immutable product_assignment_events row and a version-2 platform audit event in the same transaction. The verifier checks event payload binding, complete event coverage and duplicate references.
- Product versions, release channels, plans, entitlements and price versions are not yet implemented.
