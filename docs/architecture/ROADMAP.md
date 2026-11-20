# TLMS engineering roadmap

This roadmap is the ordered delivery plan. Phase dependencies are intentional; do not implement downstream features in ways that bypass earlier trust, data or deployment contracts.

## Phase 0 — Repository governance and project control
Public repository governance, reserved-rights policy, contribution policy, security disclosure, project charter, ADRs, branch protection, secret scanning, risk register, requirements register and definition of done.

## Phase 1 — Requirements discovery and architecture freeze
PRD; customer/vendor personas; deployment model; domain model; lifecycle/state machines; API and event contracts; credential schema; threat model; privacy/data classification; hosting and recovery requirements; acceptance traceability.

## Phase 2 — Shared-hosting qualification
Select candidate annual cPanel providers only after validating PHP/Sodium/cURL/OpenSSL/PDO, MySQL or MariaDB, HTTPS egress, webhook reachability, cron behavior, document root, resource limits, backups, restore and commercial API policy. Prove deployment on a real test account before annual purchase.

## Phase 3 — Engineering foundation and CI/CD
Laravel bootstrap; supported PHP matrix; database integration matrix; migrations; environment validation; structured logging; health checks; Pint; Larastan/PHPStan; PHPUnit/Pest; Composer audit; Semgrep; secret scanning; dependency review; pinned GitHub Actions; prebuilt deployment artifacts.

## Phase 4 — Identity, organizations and portals
Admin and customer identity; MFA; recovery; organizations; membership; roles; RBAC; tenant/object-level authorization; customer and administrator portals; session revocation; audit events.

## Phase 5 — Product registry and entitlement catalogue
Products, versions, release channels, plans, price versions, feature/seat/usage/support/update entitlements, trials, perpetual and maintenance separation, enterprise and agency terms, immutable entitlement snapshots.

## Phase 6 — Licence engine
Secure key generation and storage; lifecycle state machine; idempotent issuance; activation; domain/site/user/seat/usage limits; transfer, replacement, renewal, suspension, revocation and reinstatement; atomic concurrency control.

## Phase 7 — Cryptographic signing and key management
Versioned signed claims; canonical JSON; Ed25519/Sodium; offline root; delegated operational keys; rotation; revocation; key distribution; audit; compromise recovery; independent verifier and cross-language vectors.

## Phase 8 — Activation, domain binding and offline operation
Domain normalization and IDNA; ownership challenges; installation identity; clone, backup and migration workflows; signed offline leases; bounded revocation delay; central outage behavior.

## Phase 9 — Payments, invoicing and financial ledger
Provider adapters; fiat integrations; legally qualified crypto provider integration; signed webhooks; idempotency; reconciliation; manual cash/bank/POS issuance; maker-checker approvals; invoices; partial payments; refunds; chargebacks; tax; settlement; exchange-rate snapshots; subscriptions and dunning.

## Phase 10 — Secure software release and update distribution
TUF-compatible root/timestamp/snapshot/targets metadata; delegated roles; threshold signatures; metadata expiry; rollback/freeze/mix-and-match protection; signed packages; SBOM; provenance; channels; recovery and rollback.

## Phase 11 — PHP SDK, installer and customer distribution
Framework-independent PHP SDK; API client; local verifier; activation; entitlement checks; offline lease; prebuilt ZIP; browser installer; compatibility diagnostics; safe extraction; installer lock; no SSH/root/Composer/Node/Redis/worker requirement.

## Phase 12 — Talyvane integration
Product configuration; SDK adapter; purchase-to-install flow; activation, offline, renewal, update and failure-path end-to-end tests.

## Phase 13 — Reseller, white-label and enterprise
Reseller attribution and commissions; payout reconciliation; agency multi-client workflows; per-deployment branding; custom domains; enterprise contracts, purchase orders, entitlements and support SLAs.

## Phase 14 — Security, privacy and operational resilience
OWASP ASVS verification; application/API testing; dependency and supply-chain review; penetration test; data inventory, retention, export, deletion and DPA; legal review; independent backups; restore drills; monitoring; incident response; host migration; signing-key compromise exercise.

## Phase 15 — Beta, production launch and release certification
Functional and regression suites; shared-host acceptance; concurrency/performance; security and privacy sign-off; payment reconciliation; release artifact signature; SBOM and provenance; changelog; rollback; final forensic audit and production readiness approval.

## Cross-phase engineering gates

- TDD for every behavior change.
- CI must run on the exact candidate commit.
- No disabled tests, skipped security checks or fabricated green status.
- Database changes require forward and recovery strategy.
- API and credential changes require explicit versioning and compatibility tests.
- Secrets and private signing keys never enter source control or customer packages.
- Each phase ends with a forensic review and evidence recorded in IMPLEMENTATION-STATUS.md.
