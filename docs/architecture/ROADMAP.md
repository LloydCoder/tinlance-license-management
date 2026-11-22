# TLMS engineering roadmap

This roadmap is the ordered delivery plan. Phase dependencies are intentional; do not implement downstream features in ways that bypass earlier trust, data or deployment contracts.

## Phase 0 — Repository governance and project control
Public repository governance, reserved-rights policy, contribution policy, security disclosure, project charter, ADRs, branch protection, secret scanning, risk register, requirements register and definition of done.

## Phase 1 — Requirements discovery and architecture freeze
PRD; customer/vendor personas; deployment model; domain model; lifecycle/state machines; API and event contracts; integration credential lifecycle and Super Admin settings; platform-wide Super Admin authority and Control Center; authorization/permission matrix; threat model; privacy/data classification; hosting and recovery requirements; acceptance traceability.

## Phase 2 — Shared-hosting qualification
Select candidate annual cPanel providers only after validating PHP/Sodium/cURL/OpenSSL/PDO, MySQL 8.4 or MariaDB 10.11, HTTPS egress, webhook reachability, cron behavior, document root, resource limits, backups, restore and commercial API policy. The `tlms:hosting-preflight --json` command automates local runtime/database/security/filesystem checks; it does not replace a real provider PoC. Prove deployment on a real test account before annual purchase.

## Phase 3 — Engineering foundation and CI/CD
Laravel bootstrap; supported PHP matrix; MySQL 8.4 and MariaDB 10.11 integration matrix; migrations; environment validation; structured logging; health checks; Pint; Larastan/PHPStan; PHPUnit/Pest; Composer audit; Semgrep; full-history secret scanning; OSV-Scanner lockfile scanning; Dependabot; pinned GitHub Actions; deterministic production-only cPanel artifact packaging, including Vite browser bundles compiled with pinned Node.js/npm and with build tooling removed from the customer ZIP; SPDX SBOM generation; tag-triggered GitHub/Sigstore SLSA provenance and SBOM attestations; checksum and attestation verification; explicit release asset retrieval.

## Phase 4 — Identity, organizations and portals
Admin and customer identity; MFA; recovery; organizations; membership; roles; RBAC; tenant/object-level authorization; customer and administrator portals; session revocation; audit events; Super Admin Control Center shell and platform-wide authorization.

## Phase 4 implementation boundary

PR #20 implements the one-time browser bootstrap for the initial Super Admin. It creates the primary vendor account, initial administrator, active vendor membership and system-attributed Super Admin role grant atomically. It requires a deployment-provided secret, secure transport, CSRF, rate limiting, exact authorization-catalogue validation and a consumed installation state. It deliberately does not authenticate the administrator.

PR #23 adds the first authentication foundation: Fortify login/logout, verified email, password recovery, TOTP, WebAuthn passkeys, MFA enrollment gating, password-confirmed MFA/recovery-code management, encrypted database sessions, session-version enforcement and global session revocation. Public registration remains disabled.

PRs #63–#64 implement vendor/customer invitation creation, delivery, single-use acceptance, expiry, resend and revocation invalidation with hashed tokens and invited-identity binding. PRs #66–#67 add the tenant-scoped invitation workspace query and authorization-aware controller view model. PR #68 adds the vendor/customer invitation-management UI with create/resend/revoke controls, role allowlists, delivery/creation/expiry metadata, recent password confirmation, fresh TOTP/passkey step-up, CSRF-protected same-origin requests and token-material exclusion. The release builder compiles the browser entry points into the cPanel ZIP; Node.js is not required on customer hosting. PR #70 adds the least-privilege vendor_admin role so normal vendor tenants can use the workspace; only the first manager is bootstrapped directly by Super Admin, and later grants require maker-checker. PR #71 establishes the TLMS Brand Kit v1.0 and Usage Sheet v1.0 as the visual authority. PR #72 imports the supplied horizontal light/dark lockups, app tile, SVG favicon and canonical palette stylesheet into the authentication, tenant-workspace and setup shells. The remaining icon, descriptor, certificate, email, social and visual QA assets are tracked as incomplete brand acceptance work. PR #74 implements explicit audited vendor/customer membership reinstatement using a new invitation and low-risk role assignment; PR #75 adds tenant-scoped revoked-member queries and workspace controls. The next Phase 4 sequence is resource-level ABAC and non-membership mutation authorization.

Phase 4 remains incomplete. Explicit membership reinstatement, complete resource-level ABAC and mutation policies for product/reseller/licence/payment resources, generalized privileged step-up and typed approval workflows beyond high-risk vendor role grants, complete customer/vendor portals, comprehensive cross-tenant IDOR/concurrency coverage, stronger recovery-code assurance and end-to-end audit coverage remain acceptance requirements.

## Phase 5 — Product registry and entitlement catalogue
Products, versions, release channels, plans, price versions, feature/seat/usage/support/update entitlements, trials, perpetual and maintenance separation, enterprise and agency terms, immutable entitlement snapshots.

## Phase 6 — Licence engine
Secure key generation and storage; lifecycle state machine; idempotent issuance; activation; domain/site/user/seat/usage limits; transfer, replacement, renewal, suspension, revocation and reinstatement; atomic concurrency control.

## Phase 7 — Cryptographic signing and key managemen
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

## Current Phase 3 evidence and residual work

The current repository implements PHP 8.3–8.5 CI, MySQL 8.4 and MariaDB 10.11 integration, Composer audit, Pint, OpenAPI validation, Semgrep Community Edition, Larastan 3.15.0/PHPStan 2.3.7 level 8 across application, domain, migrations, routes, bootstrap, config and tests, Gitleaks full-history scanning, OSV-Scanner dependency scanning and weekly Composer/GitHub Actions Dependabot configuration. This does not complete Phase 3: GitHub native secret scanning/push protection, dependency graph/Dependabot alerts, required branch rulesets, independent cross-host reproducibility, install/upgrade/rollback automation, backup/restore automation and real-provider qualification remain release gates. The tag-triggered release workflow is PR-tested for deterministic artifact and SPDX SBOM generation; signed SLSA provenance and SBOM attestations, external verification and release publication remain unproven until a real protected version tag is released. CI ZIP artifacts remain unsigned. See `docs/operations/RELEASE-ATTESTATION.md`, `docs/security/SECURITY-ANALYSIS.md` and `IMPLEMENTATION-STATUS.md`.

## Cross-phase engineering gates

- TDD for every behavior change.
- CI must run on the exact candidate commit.
- No disabled tests, skipped security checks or fabricated green status.
- Database changes require forward and recovery strategy.
- API and credential changes require explicit versioning and compatibility tests.
- Secrets and private signing keys never enter source control or customer packages.
- Each phase ends with a forensic review and evidence recorded in IMPLEMENTATION-STATUS.md.
