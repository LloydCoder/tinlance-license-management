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

PRs #63–#64 implement vendor/customer invitation creation, delivery, single-use acceptance, expiry, resend and revocation invalidation with hashed tokens and invited-identity binding. PRs #66–#67 add the tenant-scoped invitation workspace query and authorization-aware controller view model. PR #68 adds the vendor/customer invitation-management UI with create/resend/revoke controls, role allowlists, delivery/creation/expiry metadata, recent password confirmation, fresh TOTP/passkey step-up, CSRF-protected same-origin requests and token-material exclusion. The release builder compiles the browser entry points into the cPanel ZIP; Node.js is not required on customer hosting. PR #70 adds the least-privilege vendor_admin role so normal vendor tenants can use the workspace; only the first manager is bootstrapped directly by Super Admin, and later grants require maker-checker. PR #71 establishes the TLMS Brand Kit v1.0 and Usage Sheet v1.0 as the visual authority. PR #72 imports the supplied horizontal light/dark lockups, app tile, SVG favicon and canonical palette stylesheet into the authentication, tenant-workspace and setup shells. The remaining icon, descriptor, certificate, email, social and visual QA assets are tracked as incomplete brand acceptance work. PR #74 implements explicit audited vendor/customer membership reinstatement using a new invitation and low-risk role assignment; PR #75 adds tenant-scoped revoked-member queries and workspace controls. PR #77 closes the platform-bootstrap Super Admin cross-tenant read gap for active vendor accounts/customer organizations and records allowed/denied decisions in application-level append-only audit events. PR #80 adds a serialized SHA-256 hash chain for those platform access events, deterministic migration backfill, a chain-head lock during verification, and the `tlms:audit:verify` operator command while preserving MySQL/MariaDB immutable-row triggers. PR #82 adds hash-chained denial evidence for non-bootstrap actors attempting cross-tenant reads without granting access. The chain is unkeyed and not externally anchored; it detects row/sequence/hash/head tampering but does not prevent a privileged database administrator from rewriting the entire database and recomputing it. PR #84 adds read-only active vendor/customer membership directories to the tenant workspace, with manager permission checks, active parent/user filtering, matching role scope and per-tenant pagination. PR #87 adds active-member role assignment, role removal and membership revocation controls wired only to the existing fresh-MFA-step-up APIs. PR #88 requires recent password confirmation plus fresh five-minute TOTP/passkey step-up before global Super Admin session revocation, with step-up controls available on the session-management page. PR #91 adds a primary-vendor customer-organization directory and create/profile-update workflow for authorized Super Admins. The workflow uses `customers.manage`, locks and revalidates the primary vendor and target organization in a transaction, prohibits client-supplied tenant/status changes, requires recent password confirmation and fresh MFA step-up, and writes append-only profile events protected by Eloquent and MySQL/MariaDB triggers. The platform-bootstrap Super Admin remains read-only even though it has a vendor Super Admin role; both policy and service boundaries explicitly deny its mutations. PR #94 appends customer-organization create/profile-update operation records to the existing SHA-256 chain, linked by actor, tenant, resource, reason code and request ID. PR #95 adds version-2 chain records for customer-organization create/profile mutations. Each record contains a unique reference to its immutable profile event and a digest of that event; the profile event's change_digest commits to canonical before/after values for the changed fields without duplicating raw PII into the audit stream. The verifier checks the linked event's type, organization, actor, tenant, request ID, reason, changed-field list and change digest. Historical version-1 records remain verifiable. PR #97 extends the same version-2 evidence contract to vendor profile display-name changes: vendor_admin receives the scoped vendor.account.profile.manage permission, the bootstrap Super Admin remains read-only, and only display_name can be changed after recent password confirmation and fresh MFA. The vendor event is immutable at the Eloquent and MySQL/MariaDB trigger layers and is linked by a unique detail reference. This provides cross-record tamper detection, not external immutability: the chain is still unkeyed and can be recomputed by a privileged database administrator who can rewrite the entire database. Generalized resource-level ABAC for product, licence, payment, reseller and release resources depends on those aggregates and service boundaries being implemented.

Phase 4 remains incomplete. The customer-organization workspace is an initial Super Admin administrative surface, and the customer-owner portal is a narrow profile surface. PR #97 adds a narrow vendor display-name profile portal for authorized vendor_admin users, but does not complete vendor contact management, legal identity workflows, billing or the full portals. Complete resource-level ABAC and mutation policies for product/reseller/licence/payment/release resources must be added as those aggregates are built. Generalized privileged step-up and typed approval workflows beyond high-risk vendor role grants, complete customer/vendor portals, comprehensive cross-tenant IDOR/concurrency coverage and a global tamper-evident audit ledger remain acceptance requirements. PR #93 adds a customer-owner profile workspace for only the caller's active organizations, limited to display name and billing contact. It adds the customer-scoped `customer.organization.profile.manage` permission only to `customer_owner`, rechecks persisted membership/role/tenant state, requires recent password confirmation and fresh five-minute TOTP/passkey step-up, writes append-only profile events, and paginates the organization list in SQL. Regression coverage includes cross-organization denial, prohibited vendor-controlled fields, billing-admin denial, stale/future assurance timestamps, audit evidence and pagination. The customer-owner portal is still a narrow profile surface, not a complete customer portal. The platform-access hash chain and customer-profile append-only events are separate streams; they have not yet been unified. PR #77 provides a narrow bootstrap Super Admin cross-tenant read exception for active vendor/customer resources with application-level audit evidence.

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


### Phase 5 implementation increment — product registry foundation (PR #98)

The initial product aggregate and tenant-scoped API are implemented: stable public identifiers, vendor-scoped slug uniqueness, draft lifecycle, optimistic version checks, creator-scoped access for vendor_product_manager and vendor Super Admin access within the vendor. Product create/update events are immutable and linked to version-2 platform audit records with canonical before/after digests. Mutations require recent password confirmation and fresh MFA and revalidate membership under lock. The creator receives an initial assignment. Non-bootstrap vendor Super Admins can grant/revoke explicit same-vendor assignments to active product managers and developers; managers can edit assigned draft products and developers have read-only assigned-product access. Grant/revoke events are immutable and linked to the version-2 platform audit chain. ReleaseChannel, Plan, PlanVersion, EntitlementDefinition, entitlement snapshots, PriceVersion, trials and licence lifecycle remain open Phase 5+ work. ProductVersion draft/publish lifecycle is implemented in the Phase 5 increment below. PR #98 does not complete Phase 5.


### Phase 4 implementation increment — product assignment ABAC

Explicit product assignments are now implemented as a tenant-bound aggregate. Product managers and developers can only list/read products assigned to their active vendor membership; developers are read-only, and product managers can update assigned draft products. Vendor Super Admins alone can grant/revoke assignments, with recent password confirmation, fresh MFA, row locking, immutable assignment events and version-2 audit-chain coverage. This closes product-level assignment ABAC only; plan, licence, payment, reseller and release authorization remain open.


### Phase 5 implementation increment — product version lifecycle

Product versions now have a tenant-scoped semantic-version identity, draft/published lifecycle, release notes, SHA-256 artifact metadata and optimistic concurrency. Product managers can create/update drafts for assigned products; developers can read assigned versions; vendor Super Admins retain vendor-wide authority. Publishing is step-up protected and requires a valid artifact digest and positive size. Published versions are immutable and every transition is represented by immutable event records linked to the version-2 audit chain. Artifact storage/signing, release channels and update distribution remain later Phase 5/7/10 work.


### Phase 5 implementation increment — release channels

Product-scoped release channels now provide unique named streams and a current published-version pointer. Product managers can create channels for assigned products; developers can read but not mutate them. Promotions require releases.publish, fresh MFA, optimistic concurrency, a same-product published version and an audit reason. Re-promoting the current version is idempotent; selecting an earlier published version supports controlled rollback. Channel history is immutable and linked to the version-2 audit chain. Artifact storage/signing and actual update distribution remain Phase 7/10 work.
