# TLMS implementation status

**Last reviewed:** 2026-11-24 (PR #121 reconciles Phase 6's domain-allocation contract; Phase 7 signing foundation is in progress)
**Current phase:** Phase 7 — cryptographic signing and key management
**Overall status:** Phase 6's licence-engine implementation has passed internal cross-service review and exact-commit CI/security verification. The merged engine includes transactional issuance and one-time encrypted secret delivery, lifecycle transitions, renewals, scheduled expiry, site/seat/user/domain allocations, usage metering, atomic transfer/replacement and request-ID collision guards. Phase 7 is now implementing the Ed25519 signing foundation; its current branch is not yet merged or accepted. Phases 7–15 remain incomplete and TLMS is not production-ready.

## Evidence-based phase status

| Phase | Scope | Status | Remaining exit evidence |
|---|---|---|---|
| 0 | Repository governance and project control | Initial governance documentation merged. Live GitHub API inspection confirms `main` is unprotected and the repository has no rulesets (`protected: false`, rulesets `[]`). | Owner must enable PR-only changes, require CI and Security Analysis checks, prevent force-push/deletion, require conversation resolution, enable GitHub native secret scanning/push protection, dependency graph and Dependabot alerts; counsel/contributor rights review. The connected GitHub actions expose read-only ruleset inspection, so repository-admin UI/API action is required. |
| 1 | Requirements and architecture freeze | Initial ADRs, threat model, OpenAPI, authorization matrix, state machines, traceability and 31 decision records are present. | Complete semantic API authorization, lifecycle contracts, privacy/controller-processor/legal review, decision owners and explicit closure evidence. |
| 2 | Shared-hosting qualification | Requirements, provider shortlist and automated `tlms:hosting-preflight --json` runtime checks implemented; no provider approved and no live account tested. | Real annual cPanel provider PoC: PHP 8.3–8.5, intl, Sodium, Argon2id, MySQL 8.4 or MariaDB 10.11, outbound HTTPS, cron, webhooks, document-root isolation, permissions, resource limits and restore drill. |
| 3 | Engineering foundation and CI/CD | Laravel 13, locked Composer dependencies, PHP 8.3–8.5 CI, Pint, Composer audit, OpenAPI lint, MySQL 8.4.13 and MariaDB 10.11 integration, Semgrep (`p/security-audit`), Larastan 3.15.0 with PHPStan 2.3.7 (level 8 across `app/`, `src/`, `database/`, `routes/`, `bootstrap/`, `config/` and `tests/`; committed lock, no baseline), pinned Gitleaks 8.30.1 full-history scan across all reachable Git refs (`--all`) with an exact synthetic-fixture/path/rule allowlist, OSV-Scanner 2.7.1 Composer lockfile/repository scanning, weekly Composer/GitHub Actions Dependabot configuration, and a reproducible production-only cPanel ZIP builder/verifier are implemented. The cPanel artifact builder compiles the passkey and invitation browser bundles with pinned Node.js 22.16.0, verifies bundle presence and reproducibility, and removes npm/Vite build tooling from the customer package. CI verifies the artifact; it is not published by default and Node.js is not a customer runtime requirement. | GitHub native secret scanning/push protection and Dependabot alert settings, enforced `main` ruleset, artifact install/upgrade/rollback automation, backup/restore automation and real cPanel PoC. |
| 4 | Identity, organizations and portals | ULID-backed user/vendor/customer schema, scoped permission catalogue, deny-by-default membership authorization, transactional allowlisted role assignment/revocation, immutable membership-role events, one-time browser-based Super Admin bootstrap and the initial authentication foundation are implemented. Fortify login/logout, email verification, password reset, TOTP, WebAuthn passkeys, MFA enrollment gating, password-confirmed MFA/recovery-code management, encrypted database sessions, fresh-database session-version enforcement and global session revocation are covered by feature tests. PR #60 makes the self-service MFA enrollment page available to every authenticated, verified active user, not only Super Admins; it preserves secure transport, session-version checks and recent password confirmation. PR #49 requires recent password confirmation before Super Admin session listing or global session revocation. PR #51 reloads persisted user status with the session version on protected web/API requests and invalidates sessions immediately when the account is suspended or missing. Password reset and session revocation are transactional; supported weaker legacy hashes are upgraded without downgrading stronger hashes. Public registration remains disabled. PR #29 adds a `CustomerOrganizationPolicy` that limits customer reads to the caller's own organization and vendor support reads to organizations under the caller's active vendor. PR #38 adds `VendorAccountPolicy::view` backed by the vendor-scoped `vendor.account.read` permission and database-reloaded membership authorization. PR #40 adds `VendorMembershipPolicy::view` and `CustomerMembershipPolicy::view`, reloading the target membership and parent resource from the database and requiring `admins.manage` or `customer.members.manage` in the matching scope. The policies permit active/invited target memberships, deny revoked/suspended/deleted memberships, and define no collection-listing or mutation abilities. PR #50 adds self-only reads for a user's own active/invited vendor or customer membership while retaining administrator permission checks for other members and denying `viewAny`, update and delete. PR #52 hardens `CustomerOrganizationPolicy::view` by reloading the persisted active organization and its vendor relationship before customer or vendor-support authorization; this denies suspended organizations and stale/mutated in-memory tenant foreign keys. PR #42 adds four session-authenticated `/api/v1` vendor/customer membership role assignment and revocation endpoints. Nested route parents are checked against the target membership; role changes reuse the transactional allowlisted service, require a reason, are idempotent, and append immutable role-event evidence. The endpoints require a verified current session, recent password confirmation, MFA enrollment, tenant-scoped permission, CSRF protection and throttling; API errors use RFC 9457 Problem Details and the contract is in OpenAPI. Active and invited memberships are supported; revoked memberships are denied. PR #45 adds a five-minute session-bound fresh MFA step-up gate to all four membership role mutation endpoints. TOTP uses Fortify's configured encrypter/provider and cache-backed newer-code verification; passkey confirmation uses the existing WebAuthn assertion event and grants assurance only to the already-authenticated user after audit commit. Success/failure step-up events are append-only at the Eloquent boundary and exclude factor secrets. TOTP requires recent password confirmation; recovery codes are not accepted. Fresh step-up is not yet applied to other privileged resources. PR #47 adds a tenant-scoped maker-checker workflow for high-risk vendor role grants (`finance_admin`, `vendor_product_manager`, `reseller_manager`). It requires a distinct maker/checker, prevents the role beneficiary from deciding their own request, requires active same-vendor membership plus `admins.manage` and `approvals.review`, and applies fresh five-minute TOTP/passkey step-up to request and decision operations. Requests are idempotency-keyed, payload-hash bound and expiring; an immutable decision record must match the persisted approved state before the role-assignment service will grant the role. Approval, decision evidence and role mutation are atomic. Lower-risk roles remain directly assignable; privileged revocation remains immediate. This is not a generalized approval engine or complete ABAC. PR #63 implements vendor/customer invitation creation, SHA-256 token-hash storage for 256-bit random single-use tokens, synchronous HTTPS-only email delivery, new-account setup, existing-account acceptance, expiry, resend and invitation invalidation on membership revocation. PR #64 adds regression coverage for verified existing customer invitees, tenant-scoped role assignment, lifecycle evidence and single-use acceptance. New-account setup verifies the invited email and enforces a 15-character minimum password; verified existing invitees must authenticate as the invited account and reconfirm their password. Invite and resend operations require fresh MFA step-up and allowlisted role assignment. Account setup rejects a different authenticated account to prevent session-confusion takeover. PRs #66–#68 add a tenant-scoped invitation workspace query, authorization-aware controller view model, and vendor/customer Blade management interface with create/resend/revoke controls, role allowlists, delivery/creation/expiry metadata, recent password confirmation, fresh TOTP/passkey step-up and same-origin CSRF-protected POST interactions. PR #70 adds the least-privilege vendor_admin role, a one-time Super Admin bootstrap for the first active manager, and maker-checker approval for all subsequent grants. Invitation tokens and token hashes are not selected or rendered. Tenant-admin membership revocation is implemented in PR #55 with fresh MFA step-up, transactional status transition and immutable lifecycle evidence. PR #57 expands lifecycle regression coverage for invited-member cancellation, customer self-revocation denial, cross-organization isolation, suspended-vendor denial and immutable event evidence. PR #58 locks and revalidates the persisted parent vendor row during customer membership revocation, closing the parent-state TOCTOU window against concurrent vendor suspension. Other resource mutation policies, product/reseller resource policies, full portals and comprehensive cross-tenant IDOR/concurrency coverage remain incomplete. PR #88 additionally requires a fresh five-minute TOTP/passkey step-up, alongside recent password confirmation, before global Super Admin session revocation. PR #91 adds a primary-vendor Super Admin customer-organization directory and create/profile-update workflow with `customers.manage`, fixed server-side tenant ownership, recent password confirmation, fresh MFA step-up and append-only profile-event evidence protected by Eloquent guards and MySQL/MariaDB triggers. The platform-bootstrap Super Admin remains read-only at both policy and service boundaries. PR #84 adds read-only active-member directory tables for tenant managers; PR #87 adds active-member role assignment, role removal and membership revocation controls using the existing step-up-protected APIs; protected platform roles and customer_owner are excluded from generic controls. PR #74 implements audited vendor/customer membership reinstatement from revoked to invited through a fresh invitation; PR #75 adds tenant-scoped revoked-member queries and workspace controls. Reinstatement removes previous roles with immutable role-event evidence, assigns only an allowlisted low-risk role, invalidates all stale invitations, and requires fresh invitation acceptance. UI writes continue to use the existing password/MFA step-up and CSRF-protected API path. PR #71 establishes the supplied TLMS Brand Kit v1.0 / Usage Sheet v1.0 as the identity authority. PR #72 imports the supplied horizontal light/dark lockups, ink tile, SVG favicon and canonical palette stylesheet, and applies them to the authentication shell, tenant workspace and initial Super Admin setup screens. Remaining brand surfaces include descriptor and stacked lockups, pinned-tab/ICO and PNG/PWA icons, admin navigation tile usage, certificate seals, invoice/email templates, social/OG/README artwork and rendered desktop/mobile visual QA. | Complete resource-level ABAC for all business resources and operations; plan/licence/payment/reseller/release mutation policies; generalized step-up and typed approval workflows for financial, licensing, release, signing-key and operational actions; full administrator/customer/vendor portals; comprehensive cross-tenant IDOR/concurrency tests; stronger recovery-code verifier lifecycle; and end-to-end/global tamper-evident audit coverage. PR #77 adds the bootstrap Super Admin's narrowly scoped cross-tenant read exception for active vendor accounts and customer organizations, with application-level allowed/denied access events and MySQL/MariaDB BEFORE UPDATE and BEFORE DELETE triggers. PR #80 adds a serialized SHA-256 event hash chain, deterministic backfill, chain-head integrity checks and `tlms:audit:verify`; PR #82 records denied cross-tenant platform-read attempts in that chain with a stable denial reason. PR #84 adds manager-only, tenant-scoped active vendor/customer membership directories with active-user filtering, scope-matched role display and independent pagination. The chain is unkeyed and not externally anchored; it does not protect against a privileged database administrator rewriting the complete database. |
| 5 | Product, plans and entitlements | Product registry, assigned-product authorization, immutable semantic product versions, audited release channels and promotion/rollback pointers (PRs #98–#101), plus tenant-scoped plan catalogue, immutable plan versions, typed entitlements, immutable published snapshots and draft optimistic concurrency (PRs #104–#105) are merged. | Complete price-version and commercial-term governance, perpetual/subscription/trial/maintenance semantics, upgrade/downgrade rules, enterprise/agency terms, and end-to-end proof that issued licences remain bound to the exact immutable entitlement snapshot. |
| 6 | Licence engine | **Implementation and internal engineering acceptance complete.** PRs #106–#120 implement the lifecycle state machine, persisted licence/event aggregate, canonical issuance fingerprints and durable idempotency, atomic issuance and encrypted one-time secret delivery, authenticated transitions and renewals, scheduled expiry, site/seat/user/domain allocations, quota-bound usage metering, atomic transfer/replacement and cross-stream request-ID guards. PR #121 fixes the domain-allocation response schema and adds a regression guard. Exact merged `main` commit `a1f74b5679f384c1430d56c25f94e4d721355db3` passed all nine CI jobs (run `45343038916`) and all four security jobs (run `45343038871`). The review covered service transaction boundaries, vendor/product/customer/plan scoping, row locks, unique constraints, one-time secret handling, step-up middleware, IDNA normalization, scheduled expiry and MySQL/MariaDB trigger parity. | Phase 8 adds installation-bound activation and offline leases. Production-host cron qualification remains Phase 2; measured contention/performance, backup/restore and adversarial concurrency stress remain Phase 14/15 launch evidence. These external/operational gates do not block the Phase 6 code implementation acceptance. |
| 7 | Cryptographic signing | **In progress — first domain increment on branch `feat/phase7-ed25519-signature-foundation`.** Adds constrained canonical JSON, Ed25519 key generation/signing with transient seed zeroization and serialization/debug redaction, TUF-compatible public-key key IDs, detached signature envelopes, distinct-trusted-key threshold verification and RFC 8032 vector tests. This branch is not merged or accepted yet. | Offline root metadata and ceremony; delegated operational roles and thresholds; public-key registry, activation/revocation and rotation; compromise recovery; production key-provider/custody; signed licence claims bound to immutable entitlement snapshots; independent verifier and cross-language interoperability vectors. |
| 8 | Activation and offline operation | Domain normalization primitive only. | Installation identity, domain binding, activation limits, signed 72-hour leases, refresh/grace, clock drift, outage, clone/restore and migration tests. |
| 9 | Payments and ledger | Not started. | Paystack/Flutterwave qualification, payment attempts/webhooks/idempotency, manual issuance, balanced append-only ledger, refunds, chargebacks, FX/tax and reconciliation. |
| 10 | Secure updates | Not started. | TUF root/targets/snapshot/timestamp metadata, key thresholds, signed artifacts, expiry, consistent snapshots, rollback/freeze protection and release tests. |
| 11 | PHP SDK and installer | Not started. | Framework-independent PHP SDK, signed offline verification, secure activation/cache, preflight installer and real cPanel install without SSH. |
| 12 | Talyvane integration | Not started. | End-to-end purchase → licence issuance → activation → renewal/revocation → update evidence. |
| 13 | Reseller, white-label and enterprise | Not started. | Reseller attribution/commission, branding, agency/enterprise entitlements, scoped staff and isolation tests. |
| 14 | Security, privacy and resilience | Initial threat model, immutable membership lifecycle evidence, MySQL/MariaDB database-enforced platform access audit immutability, and a serialized SHA-256 hash chain with operator verification are implemented (PRs #78 and #80). The chain is unkeyed and not externally anchored; a privileged database administrator able to rewrite the complete database can recompute it. | External anchoring or asymmetric signatures, separate log storage/retention, signing-key custody, privacy export/deletion/retention, independent security assessment, incident response, backup restore, measured RPO/RTO and resilience tests. |
| 15 | Beta, launch and certification | Not started. | Operational runbooks, support, load/abuse testing, measured SLA, release checklist, full forensic audit and owner sign-off. |

No phase is declared 100% complete. Phase 0 and Phase 1 remain partially complete; Phase 2 still lacks a real provider PoC; Phase 3 remains an engineering foundation, not deployment-ready; Phase 4 includes authentication, tenant-scoped invitations and management workspace, the least-privilege vendor_admin role, fresh step-up for membership mutations, maker-checker for high-risk vendor grants and explicit parent-vendor locking; it remains incomplete pending complete resource-level ABAC for business resources, non-membership mutation policies, generalized privileged-operation controls, full portals, comprehensive tenant-isolation evidence and global tamper-evident audit coverage. PR #77 closes the specific platform-bootstrap Super Admin cross-tenant read gap for active vendor/customer resources with application-level audit evidence. The TLMS brand specification is established and core application shells are branded, but the full brand asset acceptance checklist is not complete.

## Repository and pull-request history

- Public repository: [LloydCoder/tinlance-license-management](https://github.com/LloydCoder/tinlance-license-management).
- Default branch: `main`.
- PR #1 — governance baseline: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/1), merge commit `d5fb57731c16128e35e5b251d7c4d2b3bfa0284c`.
- PR #2 — Phase 1 contract/security review: closed as merged into its working branch; the resulting architecture material is reconciled in the merged documentation and current mainline.
- PR #3 — PHP domain foundation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/3), merge commit `1a446a743b4b877d32c1d28c6cc061dbf465f0b8`.
- PR #4 — Laravel 13 foundation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/4), merge commit `f7f2ed5fa71ad7bbffc406e5736f42b7db5ce093`.
- PR #5 — Phase 1 API security/contract documentation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/5), merge commit `2ed9198098d9ba2ae6ce030dbada46fb2f52bb29`.
- PR #6 — identity and membership authorization: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/6), merge commit `282e526aa4e6395d95621965a732a630022f9505`.
- PR #7 — Semgrep Community Edition and PHPStan security analysis gates: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/7), merge commit `1bf3f8bad7b8d9ffdf67a9323952482aa9e7bc55`.
- PR #8 — engineering status reconciliation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/8), merge commit `9b0b6a69ab12a0bbf06d2f24c336a0de8ee852fb`.
- PR #9 — full-history secret scanning, OSV dependency scanning and Dependabot configuration: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/9).
- PR #10 — Gitleaks Action v3 / Node 24 migration: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/10), merge commit `33dba949e3c78472117c1d3a46a0cce6fc230005`.
- PR #11 — setup-node v7.1.0: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/11), merge commit `f49169eb3b9ce3ce2f582c593de1ef4bd62d61e9`.
- PR #12 — checkout v7.0.1: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/12), merge commit `6170f6a6279c7afb988c365a0ea60ee84a4d3911`.
- PR #13 — PHPUnit 13.4.7: closed without merge because its dependency graph requires PHP 8.4+, incompatible with TLMS's PHP 8.3 support floor.
- PR #14 — Laravel-aware Larastan/PHPStan level 8 analysis: [pull request and exact-head workflow evidence](https://github.com/LloydCoder/tinlance-license-management/pull/14).
- PR #17 — shared-hosting runtime preflight and cPanel compatibility checks: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/17), merge commit `ae5d7f1174b997ef84f5c342eaf507d07e72ebcb`.
- PR #20 — one-time browser Super Admin bootstrap, transactional system-attributed role grant and security regression tests: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/20), merge commit `ada68466506bb19ab9faba08f0f506165e9dd135`.
- PR #21 — reproducible production-only cPanel ZIP builder and verifier: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/21), merge commit `be5cbe205f4bd01ce66a87469f7da772f917539d`.
- PR #23 — Fortify authentication, TOTP/WebAuthn passkeys, session revocation, recovery-code controls and password-hash upgrade foundation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/23), merge commit `5b676faf3978d375beb45a7b00188cc7be3149b8`.
- PR #27 — removes high-entropy authentication test literals and replaces event-range secret scanning with a pinned full-history Gitleaks scan: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/27), merge commit `9fa9e2a493a595b1e30948cc49ad9bb76f1de378`.
- PR #36 — signed cPanel release provenance and SBOM workflow: merged into mainline; release publication still requires a real protected version tag and independent consumer verification.
- PR #37 — Phase 3 release-attestation status reconciliation: merged into mainline.
- PR #38 — vendor-account resource authorization: implementation is present on main at `9c42f731c4a14445fc60e384ea6cf66b83aa3590`; exact-head and post-merge CI/Security evidence must be linked before this increment is marked fully verified.

## Latest verified engineering evidence

PR #20 exact head `b3b2e03ca262863b058057d93cb62522742ccc2e` was merged as `ada68466506bb19ab9faba08f0f506165e9dd135`. The post-merge [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44873350045) passed all eight jobs (PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, OpenAPI, documentation governance and cPanel artifact verification). The post-merge [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44873350044) passed all four jobs.

PR #21 exact head `03b2ea3111096321348a07b4eaa559a7084ea197` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44872660976) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44872660973). The merge commit `be5cbe205f4bd01ce66a87469f7da772f917539d` passed post-merge [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44872817454) and [main Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44872817441).

PR #21's CI verified production-only Composer installation with Composer 2.11.2, dependency metadata, recursive environment/auth/private-material exclusions, SHA-256 manifest/checksum and byte-for-byte ZIP reproducibility. Artifact upload remains manual and the ZIP is not cryptographically signed.

PR #17's shared-hosting runtime preflight was merged at `ae5d7f1174b997ef84f5c342eaf507d07e72ebcb`. The actual provider qualification remains open: CI preflight and artifact verification are not substitutes for a live annual cPanel account test.

Earlier identity baseline CI [44826085608](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44826085608) passed on PR #6 exact head `85010d60d49a1df7f1c1b8916f10fb674ea424e6`. Earlier Semgrep/PHPStan and Gitleaks/OSV gates passed in their merged PRs; their exact-head evidence remains linked in the respective PRs.

## PR #23 authentication implementation evidence

PR #23 exact head `9d742211701501e5d7a2b1b2272d6d7b1ab70d5c` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44877579451) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44877579382). The exact-head CI passed all eight jobs: PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, cPanel production artifact verification, documentation governance and OpenAPI validation. Security passed all four jobs: Larastan/PHPStan, Semgrep, Gitleaks and OSV-Scanner. The framework suite passed 82 tests; 27 framework-independent domain tests also passed. The PR #23 merge commit's first mainline Security run exposed two synthetic high-entropy password literals in test code. PR #27 replaced them with generated fixtures and upgraded Gitleaks to a pinned full-history `--all` scan with an exact value/path/rule allowlist. The PR #27 merge commit `9fa9e2a493a595b1e30948cc49ad9bb76f1de378` passed post-merge [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44878232798) and [main Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44878232747), both fully green.



## PR #27 full-history secret-scanning evidence

PR #27 exact head `2434f759ec0f8592eaa81b150dc7c4cf5638dc30` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44878114949) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44878114967). CI passed all eight jobs. Security passed all four jobs, including the new pinned Gitleaks container scanning all reachable Git history with `--log-opts="--all"`. The only allowlist is constrained to the exact synthetic password, exact test file path and `generic-api-key` rule; the current test source no longer contains that literal.

Current main head at the PR #27 checkpoint: `9fa9e2a493a595b1e30948cc49ad9bb76f1de378`. Post-merge main CI and Security were both green at that checkpoint.

## Implemented identity and authorization boundaries

- Migrations cover vendor accounts, users, password reset tokens, roles, scoped permissions, vendor/customer memberships, membership-role pivots and membership-role event tables.
- All primary identity/organization IDs use ULIDs. User email is trimmed/lowercased and uniquely indexed; password storage uses Laravel's hashed cast.
- Argon2id is the default password hash (19 MiB, 2 iterations, 1 lane); configuration cannot lower the minimum. Bcrypt is an explicit non-default fallback with cost at least 12 and a 72-byte input ceiling.
- Vendor and customer memberships are separate authorization contexts. Permission rows carry explicit vendor/customer scope.
- MembershipAuthorization re-reads actor/resource status and membership state from the database. Unknown permissions, stale suspended models, missing membership and cross-scope role/permission grants deny by default.
- MembershipRoleAssignmentService uses DB transactions and row locks; requires active actor/resource/target, actor permission, same scope, non-self target and non-empty reason. It allowlists low-risk roles, blocks generic grants of Super Admin/platform admin/finance admin/product manager/customer owner, supports idempotent assignment/revocation and records a membership-role event in the same transaction.
- Membership-role event models reject Eloquent instance updates/deletes. This is application-level append-only evidence only; it is not the global hash-chained audit ledger or a DB-level tamper-proof guarantee.
- The one-time Super Admin bootstrap and initial Fortify authentication foundation are implemented. Public registration remains disabled; Super Admin access requires verified email and TOTP or a WebAuthn passkey. Recovery codes are encrypted by Fortify and revealed only on explicit request after recent password confirmation. The current recovery-code storage is not a separate hashed-verifier ledger; stronger verifier lifecycle, TOTP replay assurance, privileged step-up, resource-level ABAC, maker-checker, portals, global hash-chained audit ledger and business APIs remain outstanding.

## Owner-level release blockers

1. Configure and verify a `main` ruleset: the current branch metadata reports `protected=false`, and the rulesets API currently returns no rulesets. Require PRs and CI/Security checks; block force-push and deletion.
2. Enable and verify GitHub native secret scanning and push protection. Gitleaks CI is not a replacement for push protection.
3. Verify dependency graph, Dependabot alerts and Dependabot security updates. Dependabot weekly update configuration and OSV-Scanner lockfile scanning are now in source control; native dependency graph and Dependabot alert settings remain unverified.
4. Complete counsel review of source-visible commercial licensing, contributor rights, trademarks, privacy roles and target-market payment compliance.
5. Qualify a real annual cPanel provider and complete backup/restore and operational recovery exercises.
6. Do not publish production SLAs or purchase annual hosting based only on provider marketing claims.


## PR #29 customer organization resource-policy evidence

PR #29 exact head `b809c43dba7bfbeba424cf0cc54b6964a8ffd036` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44878909812) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44878909738). CI passed all eight jobs: PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, cPanel production artifact verification, OpenAPI validation and documentation governance. The PHP 8.4 framework suite passed 86 tests and 256 assertions. Security passed all four jobs: Semgrep (23 rules, 139 files, zero findings), Gitleaks full-history scan, OSV-Scanner and Larastan/PHPStan. The policy is constructor-injected through Laravel's container and explicitly denies if the customer organization has no vendor account. The initial candidate exposed two defects (policy method injection is not supported by Gate's invocation contract, and the vendor relation is nullable); both were corrected before the final exact-head checks.

PR #29 was merged as `393b30c9242c69efcee9afebe4b275432b593c94`. Post-merge [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44879026686) passed all eight jobs and post-merge [main Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44879026634) passed all four jobs.

The policy currently supports only `view`. It intentionally does not define `viewAny`, create, update, delete or mutation abilities. Resource-level ABAC across the complete product, licence, reseller, payment and administrative domain remains outstanding.


## PR #32 stale authorization-state regression evidence

PR #32 exact head `63026dfa51f2f30624a50fdd36609be382ac6ca5` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44880034445) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44880034388). CI passed all eight jobs: PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, cPanel production artifact verification, OpenAPI validation and documentation governance. Security passed all four jobs: Larastan/PHPStan, Semgrep, Gitleaks full-history scanning and OSV-Scanner. The added feature tests establish that a customer organization view decision is denied after the customer's membership is revoked or the customer's role is detached, even when the request context began with an authorized state. PR #32 was merged as `d1a729b4a50c86066f6c9044ff09797d8cff14ee`.

The exact-head PR workflows are green. A separate post-merge mainline workflow run has not yet been verified in this status update; do not treat PR checks as post-merge deployment evidence.


## Phase 0–3 forensic acceptance audit (2026-11-21)

The full evidence and owner-gated acceptance matrix is maintained in [PHASE-0-3-FORENSIC-ACCEPTANCE-AUDIT.md](docs/architecture/PHASE-0-3-FORENSIC-ACCEPTANCE-AUDIT.md).

- **Main baseline:** `4e7d45c129594f60df50b1aeb108a2cfe633f786`.
- **Post-merge main CI:** [44880367359](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44880367359) — all 8 jobs passed.
- **Post-merge main Security:** [44880367355](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44880367355) — all 4 jobs passed.
- **Open PRs/issues at audit time:** none.

Phase 0 remains partial: the repository rulesets endpoint returned no rulesets; branch-protection details remain unreadable to the connected integration (403); native secret scanning/push protection/Dependabot alert settings and legal/trademark review require owner/counsel evidence. Phase 1 remains a documented architecture baseline but still needs operation-by-operation API authorization mapping, executable semantic contract tests and legal/privacy decisions. Phase 2 has preflight and operational documentation but no real annual cPanel provider account PoC. Phase 3 has green CI and reproducible production-only packaging but still lacks signed artifacts/SBOM/provenance, install/upgrade/rollback automation and tested backup/restore. None of Phases 0–3 is accepted as complete.

PR #33 — documentation reconciliation and PR #32 evidence: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/33), merge commit `4e7d45c129594f60df50b1aeb108a2cfe633f786`. PR #34 — stale authentication CI branch: [closed without merge](https://github.com/LloydCoder/tinlance-license-management/pull/34) because the current mainline already contains the corrected authentication implementation; its older divergent branch failed tests, Gitleaks and Larastan and would have reintroduced superseded code.


## Phase 3 signed release pipeline incremen

A tag-triggered release workflow is being added in PR #36. It builds the deterministic production-only cPanel ZIP, generates and validates an SPDX 2.3 SBOM from the packaged artifact, and on a pushed version tag creates GitHub/Sigstore SLSA provenance and SBOM attestations. The workflow verifies both attestations before publishing the ZIP, checksum and SBOM as release assets. Pull requests exercise the build/SBOM path without requesting OIDC signing authority. See [RELEASE-ATTESTATION.md](docs/operations/RELEASE-ATTESTATION.md).

This is not release certification: a real protected version tag, attestation publication, independent GitHub CLI verification and clean external consumer verification remain mandatory. The current CI artifact remains unsigned; the signed release path has not yet been exercised by a real tag.


## PR #38 vendor-account authorization reconciliation (2026-11-21)

**Mainline baseline:** `9c42f731c4a14445fc60e384ea6cf66b83aa3590`.

The current main branch contains the vendor-account authorization increment:

- Adds the vendor-scoped `vendor.account.read` permission.
- Adds an upgrade-safe migration that assigns this permission to the defined system vendor staff roles without granting it to customer roles or custom roles.
- Adds `VendorAccountPolicy::view`, delegating the decision to the database-reloading `MembershipAuthorization` service.
- Adds regression coverage for same-vendor access, cross-vendor denial, inactive users, suspended vendors, membership/role revocation, cross-scope denial, and denial of collection listing and mutations.
- Updates the authorization matrix to record the policy and its deliberate limitations.

**Security boundary:** the policy exposes only resource-level `view`. It does not add `viewAny`, create, update or delete abilities. It does not authorize secret credential retrieval. Customer-scope roles cannot authorize vendor-account access.

**Verification limitation:** repository file inspection confirms that the implementation and policy are present on main. The available GitHub status endpoint returned no status contexts for the observed main commit, and the commit-associated workflow lookup returned no PR-triggered runs. Therefore this reconciliation does not claim exact-head or post-merge CI/Security success. Retrieve and attach the actual PR #38 CI, Security and release-artifact workflow run URLs before accepting the increment as fully verified.

**Phase 4 remains incomplete.** Next authorization increments must be selected from the remaining resource matrix, with test-first implementation and exact-head CI/Security evidence. Do not add broad collection or mutation abilities merely to make the policy appear complete.


## PR #40 vendor/customer membership resource-policy evidence (2026-11-21)

**Merged mainline commit:** `06ee9ce39a63212740a93dedb0f823afca3993bc`.

PR #40 adds two Laravel model policies:

- `VendorMembershipPolicy::view` reloads the target membership, requires its current status to be active, reloads its vendor account, and delegates to `MembershipAuthorization::vendorCan(..., 'admins.manage')`.
- `CustomerMembershipPolicy::view` reloads the target membership, requires its current status to be active, reloads its customer organization, and delegates to `MembershipAuthorization::customerCan(..., 'customer.members.manage')`.
- Both policies deny cross-vendor/cross-organization access through the existing membership authorization boundary, which reloads actor, parent resource, membership, role and permission state.
- Regression tests cover same-scope access, cross-scope denial, insufficient role permissions, inactive target memberships, and absence of `viewAny`, update and delete abilities.
- No HTTP routes, controllers, invitation workflow or membership mutation endpoints are added by this PR.

**Exact-head verification:** PR #40 head `720ed2b9d4d429ee1b5891c89c9ad57af3fab78d` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44884617346) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44884617356). CI passed all eight jobs: PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, cPanel artifact verification, OpenAPI validation and documentation governance. Security passed all four jobs: Larastan, Semgrep, Gitleaks and OSV-Scanner. Each PHP framework suite passed 105 tests / 291 assertions; MySQL 8.4 and MariaDB 10.11 each passed 105 tests / 293 assertions.

**Post-merge verification:** merge commit `06ee9ce39a63212740a93dedb0f823afca3993bc` passed [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44884710933) with all eight jobs and [main Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44884710881) with all four jobs.

The first PR #40 candidate failed PHP syntax lint because namespace separators were double-escaped in the generated files. The defect was corrected on the PR branch before the final exact-head checks; the final head passed PHP lint, Pint, all framework suites, both database integrations, Larastan and security scans.

**Remaining Phase 4 scope:** explicit membership reinstatement; administrator/customer/vendor portals; complete resource-level ABAC for all business resources and operations; maker-checker beyond high-risk vendor role grants; fresh MFA step-up for other privileged resource operations; full cross-tenant IDOR/concurrency coverage; and end-to-end audit remain incomplete. Tenant-admin revocation of active/invited vendor/customer memberships is implemented in PR #55. This PR does not complete Phase 4.


## PR #42 membership role-management API evidence (2026-11-21)

**Merged mainline commit:** `d483e10439653bba3ea0ae8a65ec6022a403a59d`.

PR #42 implements four browser-session endpoints:

- `POST /api/v1/vendor-accounts/{vendor}/memberships/{membership}/roles/{role}` — assign an allowlisted vendor role.
- `POST /api/v1/vendor-accounts/{vendor}/memberships/{membership}/roles/{role}/revoke` — revoke an allowlisted vendor role.
- `POST /api/v1/customer-organizations/{organization}/memberships/{membership}/roles/{role}` — assign an allowlisted customer role.
- `POST /api/v1/customer-organizations/{organization}/memberships/{membership}/roles/{role}/revoke` — revoke an allowlisted customer role.

The route parent is checked against the target membership before mutation; a mismatch returns 404. The controller delegates to `MembershipRoleAssignmentService`, which enforces the role allowlist, active actor and tenant, active/invited target, no self-assignment, matching scope permission, transactional row locks, idempotency and immutable membership-role events. Every mutation requires a human-readable reason and is rate limited.

The route group uses the web session/CSRF stack, verified email, current session version, recent password confirmation (configurable password timeout), and an enrolled TOTP or passkey factor. API exceptions are rendered as RFC 9457 Problem Details with request correlation IDs. OpenAPI 3.1.1 operations, schemas and response contracts are included.

**Assurance limitation:** this is not fresh MFA step-up. The endpoint requires an enrolled factor and recent password confirmation, but does not re-challenge TOTP/passkey at mutation time. Fresh MFA challenge/freshness enforcement remains a Phase 4 security gate. Membership invitation creation, owner transfer, collection listing and direct membership mutation are not implemented by this PR.

**Exact-head verification:** PR #42 head `09befe6d3e9b8be5484e09b6f1eb2dd60c165407` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44885893974) with all eight jobs and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44885894196) with all four jobs. The [cPanel artifact/SBOM build](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44885893990) passed; tagged release publication was correctly skipped because no release tag was created. PHP 8.3/8.4/8.5 each passed 123 tests / 368 assertions. MySQL 8.4 and MariaDB 10.11 each passed 123 tests / 370 assertions. OpenAPI validation, documentation governance, cPanel artifact verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed.

**Post-merge verification:** merge commit `d483e10439653bba3ea0ae8a65ec6022a403a59d` passed [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44886010378) with all eight jobs and [main Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44886010559) with all four jobs.

The initial test-only commit `7c36bbf1b027566c3a19afccd19d5699fd70cd44` produced the expected RED result: ten endpoint tests received 404 because the routes did not exist. The final exact-head suite is green across all supported PHP and database targets.


## PR #45 fresh MFA step-up evidence (2026-11-21)

**Merged mainline commit:** `b81228561c5601891a164a17d7a684fab12c6dbd`.

PR #45 adds a five-minute, server-side session assurance marker for vendor/customer membership role assignment and revocation:

- TOTP step-up: `POST /api/v1/security/step-up/totp`; requires a verified current session, recent password confirmation and an enrolled TOTP factor. Fortify's configured encrypter and `TwoFactorAuthenticationProvider` validate the code. The provider's cache-backed newer-code check rejects reuse. Recovery codes are not accepted.
- Passkey step-up: the existing Laravel Passkeys `/passkeys/confirm` WebAuthn ceremony dispatches `PasskeyVerified`. The listener only records step-up when the event user is already the authenticated session user; passkey login for a guest does not grant step-up. Session assurance is written after the audit transaction commits.
- `EnsureRecentMfaStepUp` enforces a configurable five-minute TTL (`TLMS_STEP_UP_TTL_SECONDS`, default 300), validates the factor method and clears stale/invalid session markers.
- `mfa_step_up_events` records successful/failed TOTP and successful passkey assertions with user, method, outcome, IP, user agent and request ID. TOTP codes, secrets and passkey credentials are never stored. Eloquent model updates/deletes are rejected.
- TOTP challenges are rate limited to 5/minute; role mutations remain rate limited and require recent password confirmation, verified session, current session version, enrolled MFA, fresh step-up and tenant-scoped permission.

**Scope limitation:** fresh step-up is currently enforced for the four membership role mutation endpoints only. Refunds, ledger adjustments, signing-key rotation, release publication, backup restore, ownership transfer and other privileged resource operations still require their own policy integration. PR #47 implements maker-checker for high-risk vendor role grants; generalized approval workflows for financial, licensing, signing-key, release and operational actions remain unimplemented.

**Exact-head verification:** PR #45 head `e26b4961bef874012ce7d0592bd96d0d3b526aeb` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44887409011) and [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44887409009). PHP 8.3/8.4/8.5 each passed 134 tests / 420 assertions. MySQL 8.4 and MariaDB 10.11 each passed 134 tests / 422 assertions. OpenAPI validation, documentation governance, cPanel artifact verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed.

**Post-merge verification:** merge commit `b81228561c5601891a164a17d7a684fab12c6dbd` passed [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44887614753) with all eight jobs and [main Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44887614783) with all four jobs. Tagged release publication was skipped because no release tag was created.

## PR #46 documentation reconciliation (2026-11-21)

PR #46 reconciles the Phase 4 implementation status and authorization matrix with PR #45's fresh MFA step-up implementation.

- Merge commit: `c8effe9a7b63ca21a5d19302fdd3ff7bf5e27b1a`.
- Exact-head CI and Security analysis passed; post-merge main CI run `44889764281` and Security analysis run `44889764320` passed.

## PR #47 maker-checker approval evidence (2026-11-21)

**Merged mainline commit:** `19dc1f801eba43f30140cc0007795bf4ba224778`.

PR #47 implements tenant-scoped maker-checker approval for high-risk vendor role grants:

- Protected roles: `finance_admin`, `vendor_product_manager` and `reseller_manager`. Lower-risk role assignment remains direct; privileged revocation remains immediate.
- Request, list, view, approve and reject endpoints are tenant-scoped and require a verified current browser session, recent password confirmation, enrolled MFA and fresh five-minute TOTP/passkey step-up.
- Maker and checker must be distinct active users in the same vendor scope. The checker needs `admins.manage` and `approvals.review`; the role beneficiary cannot approve or reject their own request.
- Requests are bound to the vendor, target membership, role and action; require an idempotency key; reject key reuse with a changed payload; store a payload hash; and expire.
- Decision evidence is immutable at the Eloquent boundary. The assignment service requires a persisted approved request and matching checker decision record; a pending request cannot be used as direct authority.
- Approval state, decision evidence and role assignment are committed atomically. A failed role grant rolls back the decision and approval state.
- The OpenAPI 3.1 contract and authorization matrix document the workflow and its explicit scope limitation: this is not yet a general approval engine for payments, refunds, ledger adjustments, licence operations, releases or signing-key rotation.

**Exact-head verification:** PR #47 head `61ec092b95110bd80871a431e638dd47249b24db` passed [CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44890515579), [Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44890515569) and [cPanel artifact/SBOM build](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44890520371). PHP 8.3/8.4/8.5 each passed 151 tests / 490 assertions; MySQL 8.4 and MariaDB 10.11 each passed 151 tests / 492 assertions. The domain unit suite passed 27 tests. OpenAPI, documentation governance, cPanel artifact verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed.

**Post-merge verification:** merge commit `19dc1f801eba43f30140cc0007795bf4ba224778` passed [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44890659672) with all eight jobs and [main Security analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44890659694) with all four jobs. Tagged release attestation/publication was skipped because no release tag was created.


## PRs #48–#55 identity/authorization hardening evidence (2026-11-21)

- PR #48 — documentation reconciliation for maker-checker and Phase 0–3 status; merged as `2f39fb25587f1f27e07ad13b4c14a43547906d15`.
- PR #49 — recent password confirmation for Super Admin session listing and global session revocation; merged as `aca38e97244880dd33bf92c48039a17fd039b029`. Exact-head PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, OpenAPI, documentation, cPanel and security checks passed.
- PR #50 — self-only read access to a user's own active/invited vendor/customer membership; merged as `81a1ff402f5ee6ba1032d5d186356cd66834f31f`. Administrator-only access to other memberships and denial of collection/mutation abilities are preserved.
- PR #51 — invalidate authenticated sessions when the persisted user is inactive or missing, in addition to session-version mismatch; merged as `c14118b44e4240f22b3c80e8498c882d33ed8526`. Exact-head PHP 8.3/8.4/8.5 each passed 159 tests / 518 assertions; MySQL 8.4 and MariaDB 10.11 each passed 159 tests / 520 assertions. OpenAPI, documentation governance, cPanel verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed.
- PR #52 — reload persisted active customer organization and vendor binding before vendor-support authorization; deny suspended organizations and mutated in-memory tenant foreign keys; merged as `6718fc363ef13680ee4a3a6624a73d4c63447625`. TDD RED evidence: both new regressions failed on the previous implementation. Exact-head PHP 8.3/8.4/8.5 each passed 161 tests / 520 assertions; MySQL 8.4 and MariaDB 10.11 each passed 161 tests / 522 assertions. OpenAPI, documentation governance, cPanel verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed.
- Post-merge mainline: commit `6718fc363ef13680ee4a3a6624a73d4c63447625`; Security Analysis run [44894476317](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44894476317) passed. Main CI run [44894476296](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44894476296) was still running at the time this record was prepared; do not mark post-merge CI fully green until all jobs complete.
- PR #53 — reconcile implementation status, authorization matrix, README and changelog with PRs #48–#52; merged as `704ba47241f9089f6d3fc049dfa99c6e2bb786a1`. Exact-head and post-merge main CI/Security passed.
- PR #54 — require current active vendor membership and `admins.manage` to view a vendor role-approval request; non-maker viewers additionally require `approvals.review`. This removes the maker-only bypass after membership revocation; merged as `137ba92c740521e10df6b26600cdbef1580dcc48`. Exact-head PHP 8.3/8.4/8.5 each passed 162 tests / 524 assertions; MySQL 8.4 and MariaDB 10.11 each passed 162 tests / 526 assertions. OpenAPI, documentation governance, cPanel artifact verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed.
- PR #55 — add tenant-admin vendor/customer membership revocation with fresh five-minute MFA step-up, same-tenant active admin permission, self-revocation denial, transactional `invited|active -> revoked` transition, immutable lifecycle event, retained role pivots and idempotent retry. Merged as `8f31344682dbaf24342478cc2a1de9899c17dd12`. Exact-head PHP 8.3/8.4/8.5 each passed 168 tests / 554 assertions; MySQL 8.4 and MariaDB 10.11 each passed 168 tests / 556 assertions. OpenAPI, documentation governance, cPanel artifact verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed.
- Post-merge mainline: commit `8f31344682dbaf24342478cc2a1de9899c17dd12`; [main CI](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44895772674) passed all eight jobs and [main Security Analysis](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44895772650) passed all four jobs. The signed cPanel workflow was exercised on the non-tag path; no production release tag was created.
- Scope remains Phase 4. Invitation creation/delivery/acceptance/expiry/resend and tenant-admin management UI are implemented; explicit reinstatement, generalized ABAC, complete portals, generalized financial approvals, product/licence resources and Phase 4 as a whole remain incomplete.


## PRs #56–#58 membership lifecycle reconciliation (2026-11-21)

- PR #56 — reconciled implementation status, authorization matrix, README and changelog with PR #55 membership lifecycle revocation; merged as `dbd83ffb1037aec828af3a2fd3d2158672bb063e`.
- PR #57 — expanded membership lifecycle regression coverage for invited membership cancellation, customer self-revocation denial, cross-organization isolation, suspended vendor denial and immutable lifecycle-event evidence; merged as `ff4b63a765d2daab38a9ca3187aa1451a44d434f`. The first exact-head CI attempt exposed an import-order formatting failure; the corrected follow-up exact-head CI and Security Analysis passed. Post-merge main CI run [44896389489](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44896389489) passed all eight jobs and Security Analysis run [44896389501](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44896389501) passed all four jobs.
- PR #58 — explicitly locks and revalidates the persisted parent vendor while revoking a customer membership, in addition to locking the organization. This closes a time-of-check/time-of-use race where a concurrent vendor suspension could otherwise occur after the parent-state predicate was evaluated. Added a regression proving suspended parent vendors cannot authorize customer membership revocation and no lifecycle event is written; merged as `87b8cd7bd1b5f8aac66ecbbbb92c275e8fb23ddc`. Exact-head PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, OpenAPI, documentation governance, cPanel artifact verification, Larastan, Semgrep, Gitleaks and OSV-Scanner all passed. The cPanel artifact/SBOM build passed; tag-only release attestation was skipped because this was not a version-tag build.
- Post-merge mainline: commit `87b8cd7bd1b5f8aac66ecbbbb92c275e8fb23ddc`; [main CI run 44897410420](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44897410420) passed all eight jobs and [main Security Analysis run 44897410377](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44897410377) passed all four jobs.
- Phase 4 remains incomplete. Explicit membership reinstatement, complete resource-level ABAC, product/reseller/licence/payment policies, generalized privileged-operation step-up/approval beyond the vendor-admin grant flow, full portals and comprehensive tenant-isolation/concurrency coverage remain outstanding.


## PR #60 — general tenant-user MFA enrollment access (2026-11-21)

- The regression demonstrated that `EnsureMfaEnrolled` directed ordinary vendor/customer users to `security.mfa.enroll`, but that page was protected by `EnsureSuperAdmin`; verified non-Super-Admin users received HTTP 403 and could not complete the required enrollment.
- PR #60 removes only the Super Admin restriction from the self-service `/security/mfa` page. Authentication, verified email, secure transport, persisted session-version validation and recent password confirmation remain required. The controller displays only the current user's own TOTP/passkey state.
- Merged as `95756c706a69950ce0424dddd683f5d0da7f2929`. Exact-head PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, OpenAPI, documentation governance, cPanel artifact verification, Larastan, Semgrep, Gitleaks and OSV-Scanner passed. The cPanel artifact/SBOM build passed; tag-only release attestation was skipped because this was not a version-tag build.
- Post-merge mainline: commit `95756c706a69950ce0424dddd683f5d0da7f2929`; [main CI run 44898132244](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44898132244) passed all eight jobs and [main Security Analysis run 44898132255](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44898132255) passed all four jobs.
- Explicit membership reinstatement is the next distinct invitation lifecycle workflow. Public registration remains disabled; invite acceptance must not become a general registration or password-reset bypass.


## PRs #66–#68 — tenant-scoped invitation workspace (2026-11-22)

- PR #66 — adds the tenant-scoped invitation workspace query: database-backed permission revalidation, active-tenant scoping, 25-row tenant-specific pagination, recovery visibility for expired/failed-delivery invitations, role allowlists and token-hash/raw-token exclusion. Merged as `e6cc8c23e133db50b729c0a31ec6b785d9caf365`.
- PR #67 — adds the authenticated workspace view model. It revalidates vendor/customer manager permissions before loading tenant invitation groups and exposes recent password-confirmation, fresh MFA-step-up, TOTP/passkey availability and derived write-assurance flags. Merged as `615a3a702b7b9ac97082a1d694d2bd573a3e2f73`.
- PR #68 — adds the vendor/customer invitation-management UI and same-origin JavaScript for create/resend/revoke using existing APIs. Mutations require recent password confirmation and a fresh five-minute MFA step-up; forms use POST-only CSRF-protected fallbacks, and the JS removes hidden CSRF fields before JSON serialization. Delivery state, creation time and expiry are rendered; raw invitation tokens and token hashes are not.
- PR #68 also adds a CI browser-assets build and extends the cPanel artifact builder to compile both browser entry points with Node.js 22.16.0/npm, verify package inclusion/reproducibility and remove Node/npm/Vite tooling from the customer ZIP. Node.js is a build-time requirement only.
- PR #68 merged as `77f7682ab07ae44253d3d32ae719378a04acf056`. Exact-head [CI run 44983322799](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44983322799), [Security Analysis run 44983322801](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44983322801) and [cPanel artifact/SBOM run 44983322813](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44983322813) passed. The tag-only attestation/publish job was skipped because no version tag was created.
- Phase 4 remains incomplete. Explicit membership reinstatement, complete resource-level ABAC, policies for product/reseller/licence/payment resources, generalized privileged step-up/approval, complete administrator/customer/vendor portals, broader cross-tenant IDOR and concurrency coverage, and end-to-end audit/export remain outstanding. No production deployment or release is claimed.


## PR #70 — vendor administrator provisioning (2026-11-22)

- Adds the seeded vendor_admin role with only vendor.account.read, admins.manage and audit.read; it cannot review approvals, administer platform settings, manage customers, access payments, publish releases or operate signing keys.
- The first active vendor administrator can be bootstrapped only by a Super Admin through the existing password-confirmed and fresh-MFA role mutation path. The transaction serializes under the vendor row lock, rejects invited/unaccepted targets and existing active/invited vendor-admin assignments, and records the immutable membership-role event with actor and reason.
- All subsequent vendor-admin grants require the existing maker-checker workflow. Vendor administrators may request a grant but cannot approve it. The role remains excluded from direct invitation and generic assignment allowlists.
- The invitation workspace tests now use the real seeded role instead of injecting admins.manage into platform_admin.
- Phase 4 remains incomplete; explicit membership reinstatement, broad resource-level ABAC, complete portals, and generalized approvals remain outstanding.


## PRs #70–#72 — vendor administration and TLMS brand system (2026-11-22)

- PR #70 — adds the least-privilege `vendor_admin` role with only `vendor.account.read`, `admins.manage` and `audit.read`. A Super Admin can bootstrap the first active vendor administrator under the existing password-confirmed/fresh-MFA path; subsequent grants require independent maker-checker approval. The role cannot approve grants and is excluded from direct invitation assignment. Merged as `76b5ea96522e977fa5be769bacd9627160ef3a38`.
  - Exact-head CI run [44984654604](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44984654604) and Security Analysis run [44984654631](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44984654631) passed all jobs. The signed cPanel artifact build passed; tag-only attestation/publication was not exercised because no release tag was created.
- PR #71 — establishes the TLMS Brand Kit v1.0 and three-page Usage Sheet v1.0 as the authoritative visual implementation contract. Documentation only; no runtime changes. Merged as `1c6856a118dad7e3c4eb00037da67111b8dc54fb`.
  - Exact-head CI run [44984158423](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44984158423), Security Analysis run [44984158464](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44984158464) and signed cPanel workflow [44984158422](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44984158422) passed. Tag-only attestation/publication was skipped.
- PR #72 — imports the supplied horizontal light/dark lockups, ink tile, SVG favicon and canonical palette stylesheet without redrawing the artwork. Applies the shared palette and responsive styles to the authentication shell, tenant workspace and one-time Super Admin setup/completion pages. Existing routes, CSRF, MFA and invitation behavior are unchanged. Merged as `7f26387be7a47a7c287d628bceffe579d478e731`.
  - Exact-head CI run [44986508178](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44986508178) passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser build, OpenAPI, documentation governance and cPanel artifact verification. Security Analysis run [44986508169](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44986508169) passed Gitleaks, OSV-Scanner, Larastan and Semgrep. Signed cPanel artifact build [44986508160](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44986508160) passed; tag-only attestation/publication was skipped.
- Brand integration remains partial. Descriptor/stacked lockups, complete favicon and PWA/OS icon families, pinned tab, certificate seals, invoice/email templates, social/OG/README images and rendered desktop/mobile visual regression evidence remain outstanding.
- Phase 4 remains incomplete. Membership invitations, acceptance, revocation, audited reinstatement and workspace controls are implemented. Platform-bootstrap Super Admin cross-tenant reads for active vendor accounts/customer organizations are now explicitly authorized and application-audited (PR #77). The next distinct engineering slice is generalized resource-level authorization and mutation controls for business resources; generalized privileged-operation assurance/approvals and full portals remain outstanding. Global Super Admin session revocation now requires fresh MFA step-up (PR #88).


## PRs #74–#75 — membership reinstatement and workspace controls (2026-11-22)

- PR #74 — adds vendor/customer reinstatement endpoints behind existing verified-session, recent-password, enrolled-MFA, fresh five-minute TOTP/passkey step-up, CSRF and throttling middleware. Reinstatement moves only revoked to invited, removes all previous role pivots with immutable role-event evidence, assigns a directly allowlisted low-risk role, invalidates all prior unaccepted invitation tokens, stores only the SHA-256 hash of a fresh 256-bit invitation token, and requires normal verified invitation acceptance before access resumes. Removal of previous super_admin/platform_admin roles requires an acting vendor Super Admin. Merged as  d826bd274a5443896c2e2061e9b0bf1b6c4be4b7 .
  - Exact-head CI run [44987588272](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44987588272) passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser build, OpenAPI, documentation governance and cPanel artifact verification. Security Analysis run [44987588178](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44987588178) passed Gitleaks, OSV, Larastan and Semgrep. Signed cPanel artifact build [44987588168](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44987588168) passed; tag-only attestation/publication was skipped.
- PR #75 — adds tenant-scoped, paginated revoked-membership queries for vendor and customer workspaces, restricted to active parent tenants and active target accounts with immutable latest revocation evidence. The workspace shows previous roles, revocation time/reason and a new low-risk role/reason form. Client code calls only the new MFA-gated reinstatement API; it does not directly change membership state or role pivots. Email-delivery failure after committed reinstatement refreshes the workspace so the failed invitation can be resent. Merged as  1c00b76d590035102e55dc27f40ff2ebfdf5d773 .
  - Exact-head CI run [44988366608](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44988366608) passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser build, OpenAPI, documentation governance and cPanel artifact verification. Security Analysis run [44988366710](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44988366710) passed Gitleaks, OSV, Larastan and Semgrep. Signed cPanel artifact build [44988366607](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44988366607) passed; tag-only attestation/publication was skipped.
- The remaining Phase 4 scope is complete resource-level ABAC for vendor/customer resources, non-membership mutation policies, generalized privileged-operation step-up/approval and full customer/vendor/reseller/enterprise portals.


## PR #77 — platform Super Admin cross-tenant resource reads (2026-11-22)

- Reproduced and fixed a policy gap: the original installation bootstrap Super Admin could not view active vendor accounts or customer organizations belonging to other vendor tenants, despite ADR 0003 defining platform-wide Super Admin authority.
- Added `PlatformSuperAdminAuthorization`. Platform-wide authority is bound to the persisted `platform_installations.bootstrapped_user_id`, active user status, active membership on the active primary vendor and retained vendor-scoped `super_admin` role. A vendor-scoped role on another account is not sufficient.
- Extended `VendorAccountPolicy::view` and `CustomerOrganizationPolicy::view` for active cross-tenant resources only. Resource and tenant status/relationships are reloaded from the database. Suspended users, primary vendors, target vendors and customer organizations fail closed. No collection listing, create, update or delete ability is granted.
- Added `platform_access_audit_events` with actor, action, resource, tenant, allow/deny outcome, reason code and request correlation ID. Eloquent update/delete is rejected. This is application-level append-only evidence; a database-enforced/global hash-chained audit ledger and read-event integration across all resource types remain future work.
- RED evidence: before implementation, exact-head CI failed the two new positive authorization tests for cross-tenant vendor and customer-organization reads. After implementation, PHP 8.3/8.4/8.5 feature suites, MySQL 8.4 and MariaDB 10.11 integration suites passed; security analysis passed Gitleaks, OSV, Larastan and Semgrep; cPanel artifact verification, browser build, OpenAPI and documentation governance passed.


## PR #78 — database-enforced platform access audit immutability (2026-11-22)

- Added MySQL/MariaDB BEFORE UPDATE and BEFORE DELETE triggers to `platform_access_audit_events`. Query-builder and direct row UPDATE/DELETE attempts now fail with SQLSTATE 45000, rather than relying only on Eloquent model-instance guards.
- SQLite framework tests skip trigger-specific assertions; MySQL 8.4 and MariaDB 10.11 integration jobs execute both query-builder mutation regressions. The RED run failed both update and delete tests on both database engines before the triggers were added; the implementation candidate passes both integration jobs.
- This prevents mutation through the normal application database connection while triggers remain installed. A privileged database administrator can still drop triggers or rewrite storage. A global hash chain, external anchoring, backup retention and independent audit verification remain future work.


## PR #80 platform access audit hash-chain evidence (2026-11-22)

- **Merged mainline commit:** `b3d1d432f4a76cd26fa90a98922086fd3e432541`.
- **Exact candidate head:** `4f9b791aec0462aa74722825f45d895327c6da46`.
- **Exact-head CI:** [push CI run 44993860817](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44993860817) — all nine jobs passed, including PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, OpenAPI, browser assets, documentation governance and cPanel artifact verification.
- **Exact-head Security:** [run 44993865492](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44993865492) — Larastan, Semgrep, Gitleaks and OSV-Scanner passed.
- **Exact-head release artifact/SBOM:** [run 44993865449](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44993865449) — production cPanel artifact and SPDX SBOM build passed. Tag-only attestation/publication was correctly skipped for this pull request.
- The writer serializes appends on a single chain-head row; the verifier locks that row while checking sequence continuity, event hashes, predecessor links, hash version and chain-head agreement. Migration backfills existing events deterministically and reinstalls the MySQL/MariaDB immutable-row triggers.
- A regression test exposed that Eloquent mass assignment discarded the writer-generated ULID because `id` is intentionally not fillable. The trusted chain writer now uses `forceFill` for its internally generated ID, ensuring the persisted identifier is the one hashed without widening the model's public mass-assignment surface.
- `tlms:audit:verify` is the operator verification command.
- **Security limitation:** SHA-256 is unkeyed. A database administrator able to rewrite every event and the chain head can recompute the chain. External anchoring/asymmetric signatures and independent log retention are still required before describing the ledger as independently tamper-evident.
- **Post-merge main CI/Security:** [main CI run 44993982584](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44993982584) passed all nine jobs, and [main Security run 44993982578](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44993982578) passed all four jobs on merge commit `b3d1d432f4a76cd26fa90a98922086fd3e432541`.


## PR #82 — audit denied cross-tenant platform-read attempts (2026-11-22)

- Closes a denial-path audit gap: previously, non-bootstrap actors returned false before the platform access audit writer was called.
- Reloads the persisted actor and target tenant; when both still exist, records a hash-chained denied event with reason `actor_not_platform_super_admin` while keeping the authorization decision denied.
- Existing bootstrap Super Admin checks remain bound to the installation bootstrap user, active account, active primary-vendor membership and retained Super Admin role. Inactive tenant/resource denial reasons remain distinct.
- Exact-head CI [44994225754](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44994225754), Security Analysis [44994229747](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44994229747), and cPanel artifact build [44994229812](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44994229812) passed.
- Merged as `b39b57e483e3d263c74c93e40c5da995e2a6b978`.


## PR #84 — tenant-scoped active membership directories (2026-11-22)

- Adds a dedicated read-only `MembershipDirectoryQuery` for vendor and customer active memberships.
- Revalidates `admins.manage` or `customer.members.manage` before listing; scopes each query to the exact tenant, active membership, active user and active parent resources.
- Excludes invited/revoked memberships and inactive users; those states remain in their existing invitation/revocation sections.
- Selects minimal membership/user fields, loads only roles matching the membership scope and paginates 25 rows per tenant with independent page parameters.
- Integrates accessible vendor/customer active-member tables into the existing workspace. No new mutation path is introduced.
- Exact-head CI [44995332442](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44995332442), Security Analysis [44995332409](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44995332409), and cPanel artifact build [44995332439](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44995332439) passed.
- Merged as `ad60f479e1955a7a93953207bacbae8de2018575`.


## PR #86 — hashed MFA recovery-code vault (2026-11-22)

- Merged as `9231e07a2b412a7e4c74236b1cbd31fa56c955a6`.
- Recovery codes are high-entropy random values; only SHA-256 digests are persisted. Consumption is atomic and single-use, and a replacement is issued after successful use.
- Regeneration and authenticator reset invalidate outstanding codes. Legacy Fortify recovery-code ciphertext is migrated to digests; the legacy field is retained only as an encrypted empty array.
- Recovery codes are disclosed only through the authenticated one-time session flow. Notifications are emitted for issuance/regeneration and use.
- Exact-head CI, security analysis and signed cPanel artifact workflow passed before merge.

## PR #87 — active-member workspace controls (2026-11-22)

- Merged as `f178fb6960bdb8501355fd395157d84dc3eaf815`.
- Vendor/customer workspace now supports assigning allowlisted roles, removing revocable roles and revoking another member's membership.
- Protected platform roles and customer_owner are excluded from generic controls; self-membership changes are not exposed.
- UI reuses the existing server-authorized, password-confirmed, fresh-MFA-step-up APIs; it adds no independent mutation authority.
- Exact-head CI (PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser assets, OpenAPI, documentation governance and cPanel verification) and Security Analysis passed. Post-merge main CI and Security Analysis passed.

## PR #88 — fresh MFA for global session revocation (2026-11-22)

- Merged as `4e21956d395b2df535b24e13e94ac30e90e9a88a`.
- Global Super Admin session revocation now requires both recent password confirmation and a fresh five-minute TOTP/passkey step-up.
- The session-management page exposes existing TOTP and passkey step-up controls. A request without fresh step-up receives `423 MFA_STEP_UP_REQUIRED` and does not revoke sessions.
- Exact-head CI, Security Analysis and cPanel artifact/SBOM workflow passed. Post-merge main CI and Security Analysis are being verified.


## PR #91 — primary-vendor Super Admin customer organization management (2026-11-22)

- Merged as `68261b3e07e3f1fa0d1e2223451bdeaf5a340417`.
- Adds a tenant-scoped customer organization directory and create/profile-update workflows for the primary vendor installation.
- `viewAny`, `create` and `update` are explicitly authorized by `customers.manage`; the platform-bootstrap Super Admin read-only exception is denied in both the policy and service layer.
- Tenant ownership and lifecycle status are server-controlled. `vendor_account_id` and `status` are prohibited inputs; updates reload and lock the primary vendor and target organization within a transaction.
- Writes require recent password confirmation and a fresh five-minute TOTP/passkey step-up. Duplicate external references are checked within the primary vendor scope.
- Each creation/profile update records actor, reason, changed-field names and a sanitized request correlation ID. The event model rejects Eloquent update/delete; MySQL 8.4 and MariaDB 10.11 enforce BEFORE UPDATE/DELETE triggers.
- The events are append-only but are not yet included in the platform-access SHA-256 hash chain. This is not the complete global audit ledger.
- Exact-head CI passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser assets, OpenAPI, documentation governance and cPanel artifact verification. Security Analysis passed Larastan, Semgrep, Gitleaks and OSV-Scanner. Signed release publication was correctly skipped because no version tag was created.
- This is an initial administrative customer-management surface, not a complete customer portal. No customer deletion, suspension, billing mutation or licence operations are exposed.


## PR #93 — customer-owner profile self-service (2026-11-22)

- Merged as `3e4a8f3f7bf8182ecd624de8b70d2008d28d8c41`.
- Adds the customer-scoped `customer.organization.profile.manage` permission to `customer_owner` only.
- Adds a customer profile workspace scoped in SQL to active customer organizations, active vendor accounts, active memberships and the customer-owner permission; the list is paginated at 25 records.
- Customer owners can update only their own organization's display name and billing email. Legal name, external reference, tenant ownership and lifecycle status remain vendor-controlled.
- Writes require recent password confirmation and fresh five-minute TOTP/passkey step-up; vendor/customer profile events remain transactional and immutable.
- Fixes a shared assurance defect: future password-confirmation timestamps are no longer accepted as fresh. Error detail is now action-neutral.
- Exact-head CI passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser assets, OpenAPI, documentation governance and cPanel artifact verification. Security Analysis passed Larastan, Semgrep, Gitleaks and OSV-Scanner. Signed release publication was correctly skipped because no version tag was created.

## PR #94 — hash-chain customer organization profile mutations (2026-11-22)

- Merged as `5a87cf6bae81c1b1cd1f699b65ac59dbd03d9498`.
- Adds `customer_organization.created` and `customer_organization.profile_updated` operation records to the existing serialized SHA-256 audit chain.
- Records actor, action, resource, tenant, allowed outcome, action-specific reason code and the same normalized request correlation ID used by the detailed profile event.
- The profile mutation and hash-chain append occur in the same outer database transaction; an audit append failure rolls back the business mutation.
- Model validation restricts action/resource/reason combinations and prevents denied outcomes for mutation events.
- **Assurance boundary:** the chain attests to the mutation operation and its correlation metadata. The detailed changed-field and reason payload remains in a separate immutable profile-event table and is not yet included in the chain hash. The ledger is not yet a complete payload-level global audit ledger and remains unkeyed/unanchored.
- Exact-head CI passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser assets, OpenAPI, documentation governance and cPanel artifact verification. Security Analysis passed Larastan, Semgrep, Gitleaks and OSV-Scanner. Signed release publication was correctly skipped because no version tag was created.


## PR #95 — payload-bound customer organization audit evidence (2026-11-23)

- Adds hash version 2 for customer-organization creation and profile-update audit records while retaining verification compatibility for historical version-1 events.
- Adds a unique details_ref_id and details_hash to the platform audit event. The reference binds one platform audit record to one immutable customer profile event.
- Adds change_digest to new customer profile events. It commits to canonical before/after values for changed fields without copying raw profile values into the audit stream.
- The verifier cross-checks the referenced profile event's type, organization, actor, tenant vendor, request ID, reason, changed fields and change digest before accepting the version-2 chain record.
- The profile mutation, profile event and chain append remain inside the same outer database transaction; a failed append rolls back the mutation.
- Historical version-1 records remain unchanged and verifiable. The hash chain remains unkeyed and externally unanchored; a privileged database administrator able to rewrite all records and recompute the chain can still defeat it.
- Exact-head CI passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, browser asset build, OpenAPI validation, documentation governance and cPanel artifact verification. Security Analysis is required before merge.


## PR #97 — vendor profile administration and payload-bound audit evidence (2026-11-23)

- Adds vendor.account.profile.manage to the scoped permission catalogue and grants it to vendor_admin; vendor super_admin inherits it through the vendor permission set.
- Adds /workspace/vendor-profile for an active primary-vendor member with the permission. The bootstrap Super Admin remains read-only at both policy and service boundaries.
- Limits writes to the customer-facing display_name. Legal identity, installation key, account status, default currency and timezone are prohibited from this path.
- Requires recent password confirmation and fresh five-minute TOTP/passkey step-up. The write transaction locks and revalidates the primary vendor and the caller's active vendor membership before mutation.
- Writes a separate immutable vendor_account_profile_events record with a SHA-256 digest of canonical before/after display-name values, then appends a version-2 platform audit event referencing that record. The verifier checks actor, vendor/tenant, request ID, event type, reason, changed fields and digest.
- Eloquent guards and MySQL/MariaDB BEFORE UPDATE/DELETE triggers protect profile-event rows. Regression tests cover authorized and unauthorized access, bootstrap read-only behavior, prohibited fields, missing fresh MFA, payload tampering and database-level immutability.
- Exact PR merge-ref CI passed PHP 8.3/8.4/8.5, MySQL 8.4, MariaDB 10.11, OpenAPI, browser assets, cPanel artifact, documentation governance, Larastan, Semgrep, Gitleaks and OSV-Scanner.
- This is a narrow vendor display-profile surface, not a complete vendor portal. It does not add legal identity editing, vendor contact management, commercial defaults, billing, or generalized resource administration.


## PR #98 — product registry foundation (2026-11-23)

- Adds tenant-owned products with stable internal ULIDs and public UUIDs, vendor-scoped unique slugs, draft lifecycle state and optimistic resource versions.
- Adds product creation/list/read/update endpoints. Product managers and developers list/read only products assigned to their active vendor membership; developers are read-only, and product managers can update assigned draft products. Vendor Super Admins can read/manage all products in their vendor account and alone grant/revoke assignments.
- Product updates are draft-only and require expected_version. Vendor ownership, public ID, creator and lifecycle status are server-controlled. Slug uniqueness is enforced by both vendor-row serialization and a database unique constraint.
- Product create/update operations require recent password confirmation and fresh MFA step-up, recheck active membership under row lock, and write immutable product_events plus version-2 platform audit evidence in the same transaction.
- The audit verifier checks product, vendor/tenant, actor, event type, request ID, reason, changed fields and canonical before/after digest. Eloquent guards and MySQL/MariaDB triggers protect product event history.
- Exact PR merge-ref CI and Security Analysis are required before merge.
- This is the product catalogue foundation only. Product version draft/publish lifecycle is implemented in the Phase 5 increment below. Release channels, plans/plan versions, entitlement definitions/snapshots, price versions, trials and licence issuance remain incomplete.


## Phase 4 increment — explicit product assignment ABAC (2026-11-23)

- Adds a stable product-assignment aggregate scoped to product, vendor account and active vendor membership. One assignment row per product/membership supports audited revoke and regrant transitions without deleting history.
- Product creators receive an initial assignment. Non-bootstrap vendor Super Admins can grant/revoke assignments only for active same-vendor members whose roles carry products.read or products.manage.
- Vendor product managers can list/read and update assigned draft products. Vendor developers can list/read assigned products but cannot edit catalogue metadata. Vendor Super Admins retain vendor-wide product visibility and draft management.
- Grant/revoke writes require the existing recent-password and fresh five-minute TOTP/passkey step-up middleware. The service locks and revalidates product, vendor, actor and target membership in one transaction.
- Each grant/revoke creates an immutable product_assignment_events record and a version-2 platform audit-chain event. The verifier checks assignment/product/vendor/membership/actor/request binding, complete event coverage and duplicate references. MySQL/MariaDB BEFORE UPDATE/DELETE triggers protect assignment-event rows.
- This closes the product-assignment portion of Phase 4 resource-level ABAC. It does not complete Phase 4: generalized policies for plans, licences, payments, resellers and releases, broader privileged-operation approvals and full portals remain open.


## Phase 5 increment — immutable product version lifecycle (2026-11-23)

- Adds product-scoped semantic versions with unique (product_id, version), draft/published states, release notes, SHA-256 artifact metadata and optimistic version numbers.
- Product managers with plans.entitlements.manage can create/update drafts only for assigned products; developers can read versions for assigned products but cannot create or edit them. Vendor Super Admins retain vendor-wide product version authority.
- Publishing requires releases.publish, a positive artifact size, a 64-character SHA-256 digest, recent password confirmation and fresh TOTP/passkey step-up. Published version metadata is immutable.
- Product version events are immutable, transactionally linked to version-2 platform audit events, verified for tenant/actor/request/payload binding and complete unique event coverage. MySQL/MariaDB triggers protect event history and enforce version state transitions.
- Artifact bytes, artifact storage, signing, release channels, rollout policies and update distribution are not implemented by this increment.


## Phase 5 increment — release channels and promotion control (2026-11-23)

- Adds product-scoped release channels with unique slugs, active lifecycle, immutable identity and optimistic version numbers.
- Product managers with plans.entitlements.manage can create channels for assigned products; assigned developers can list/read channels but cannot create or promote them.
- Promotion requires releases.publish, recent password confirmation, fresh TOTP/passkey step-up, a same-product published version, a reason and expected_version. Repeating the current version is idempotent; promoting an earlier published version provides a controlled rollback path.
- Channel creation/promotion writes immutable release_channel_events and version-2 platform audit events. The verifier checks channel/product/vendor/actor/version/request binding, complete event coverage and duplicate references. MySQL/MariaDB triggers protect event history and enforce pointer/version transitions.
- Semantic-version validation now enforces SemVer 2.0.0 identifier rules, including no leading zeros in numeric prerelease identifiers.
- Artifact storage, artifact signing, rollout policies, update polling/distribution, plan catalogue, entitlements, pricing and trials remain incomplete.
