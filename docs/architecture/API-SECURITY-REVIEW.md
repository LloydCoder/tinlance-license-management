# API security and contract review

**Review date:** 2026-11-20  
**Contract:** `docs/api/openapi.yaml` (OpenAPI 3.1.1)  
**Status:** Architecture review; no endpoint implementation is claimed.

## 1. Validator evidence and limits

The GitHub Actions workflow runs `@redocly/cli@1.34.24 lint` with the `spec` ruleset. This checks OpenAPI document validity and lint rules. It does **not** prove that runtime authorization, business invariants, raw webhook signature verification, idempotency, transaction boundaries or response redaction are correctly implemented.

Do not describe a passing lint job as API security certification or executable contract-test coverage.

## 2. Global security rule

The OpenAPI document applies `AdminSession` globally. Every operation intended for another principal or for anonymous access must explicitly override that default. The existing liveness, readiness, SDK activation/verification and payment webhook operations do so. New operations must follow the same explicit rule.

OpenAPI security schemes describe authentication mechanisms, not the complete TLMS RBAC/ABAC decision. Each protected operation must additionally declare its permission, resource scope, step-up/MFA requirement, audit obligation and expected negative outcomes in this register and, where useful, operation-level extensions.

## 3. Existing operation authorization register

| Operation | Principal / permission | Scope and controls | Required audit / negative tests |
|---|---|---|---|
| `GET /health/live` | Anonymous | No dependency details or secrets | No sensitive diagnostics; rate/abuse controls |
| `GET /health/ready` | Monitor token | Readiness only; separate from admin credentials | Invalid token denied; redact dependency details |
| `GET /me` | Human admin session | Current actor only; session assurance | Unauthenticated 401; revoked session 401 |
| `GET /products` | Authenticated admin actor with `products.read` | Vendor installation scope; deny-by-default | Cross-vendor/object access denied |
| `POST /licences` | Super Admin or explicitly authorized issuer; API token requires `licences.issue` | Vendor/customer/product scope; plan snapshot; manual/free grant reason; step-up and approval policy for privileged grants; idempotency | Issue success/denial, duplicate request, same key with changed payload, scope escape, one-time key response |
| `GET /licences/{licenceId}` | Actor with `licences.read` | Resource ownership and customer scope; never return key verifier | IDOR and cross-customer denial; no secret leakage |
| `POST /activations` | SDK installation principal | Product + licence + installation binding; atomic activation limit; nonce/replay controls; idempotency | Invalid/replayed nonce, concurrent activation cap, domain normalization, revoked/expired licence |
| `POST /activations/verify` | SDK installation principal | Bound installation and signed lease; nonce/replay policy; bounded offline lease | Tamper, wrong audience/product/install, expiry, clock rollback and revoked state |
| `GET /admin/integrations/{provider}/credentials` | Super Admin; `integrations.credentials.read_metadata` | Metadata only; never secret readback | Non-Super-Admin denied; response schema forbids secret fields |
| `POST /admin/integrations/{provider}/credentials` | Super Admin; `integrations.credentials.write` | MFA + recent re-authentication; validated provider field schema; write-only encrypted values; SSRF-safe adapter allowlist | Denial without step-up; plaintext/log/exception leakage tests |
| `POST .../{credentialId}/test` | Super Admin; `integrations.credentials.test` | MFA + recent re-authentication; test only staged version; outbound destination fixed by provider adapter; idempotent result semantics | Invalid credential, timeout, DNS rebinding/SSRF, no secret disclosure |
| `POST .../{credentialId}/activate` | Super Admin; `integrations.credentials.activate` | MFA + recent re-authentication; validated version only; atomic active-version swap; maker-checker if policy requires | Concurrent activation, stale version, failed rollback, audit and no secret readback |
| `POST /webhooks/payments/{provider}` | Provider-specific signature principal | Verify exact raw body, timestamp and signature; replay window; provider/event uniqueness; map provider to configured environment; durable inbox only | Invalid signature, replay, wrong environment, amount/currency mismatch; no direct entitlement grant |
| `GET /admin/audit-events` | Super Admin or actor with `audit.read` | Field-level redaction and installation scope | Tamper evidence, scope denial, secrets absent |

The permission strings above are the target policy identifiers. Before implementation, reconcile any identifier with the canonical permission catalogue in `AUTHORIZATION-MATRIX.md`; do not silently introduce a second naming system.

## 4. Contract gaps to close before API implementation

1. **Credential lifecycle:** add explicit disable/revoke operations and define whether testing/activation are idempotent. Credential rotation must preserve the prior active version until a validated replacement is atomically activated.
2. **Step-up semantics:** document how browser session assurance is conveyed and enforced. MFA must be verified server-side; a client-supplied boolean or UI state is not evidence.
3. **Idempotency:** specify key scope (actor + operation + resource), request digest, retention, concurrent request behavior, replayed response handling and same-key/different-payload conflict. Require keys on all non-repeatable mutations.
4. **Authorization contract:** add operation-level permission and scope metadata, including Super Admin-only restrictions. A global session scheme is not a role check.
5. **Payment and financial surface:** future contracts are required for checkout/payment intent, invoices, reconciliation, refunds, disputes, manual payment evidence and ledger reads. Keep financial events separate from licence state.
6. **Commercial lifecycle:** future contracts are required for plans/immutable plan versions, subscriptions, renewals, cancellations, proration, grace and dunning.
7. **Usage and reseller:** future contracts are required for metering/quotas, reseller attribution, commissions, statements and payout reconciliation.
8. **Secure updates:** future contracts are required for TUF metadata roles, metadata retrieval, artifact manifests/downloads, key rotation and release withdrawal.
9. **Operations and privacy:** future contracts are required for data export/deletion requests, backup/restore approvals, monitoring, support cases and operational audit evidence.
10. **SDK compatibility:** publish stable error codes, version negotiation, canonicalization rules, signature envelope, key identifiers and language-independent test vectors before PHP SDK implementation.

## 5. Webhook invariants

OpenAPI cannot enforce verification against the exact raw HTTP request bytes. The implementation must read and preserve raw bytes before JSON parsing, validate provider-specific signature and timestamp rules, compare the configured provider/environment, enforce replay protection and durably insert a unique inbox event. Asynchronous processing must re-fetch or verify authoritative payment state before allocating funds or granting a licence. Never trust a webhook body field as the sole source of amount, currency, customer, order or entitlement truth.

## 6. Release gate

Before Phase 1 contract review is accepted:

- [ ] Canonical permission identifiers reconciled with `AUTHORIZATION-MATRIX.md`.
- [ ] Every OpenAPI operation has a documented actor, permission, scope and authentication scheme.
- [ ] Every privileged mutation defines step-up/MFA and maker-checker conditions.
- [ ] Every mutation defines idempotency behavior or documents why it is naturally idempotent.
- [ ] Missing lifecycle endpoints are either added to the versioned API contract or explicitly deferred with product impact recorded.
- [ ] OpenAPI lint and future runtime contract tests pass on the exact candidate commit.

Before production API release, all controls above require implementation and executable security tests. This review is not a claim that any endpoint exists today.
