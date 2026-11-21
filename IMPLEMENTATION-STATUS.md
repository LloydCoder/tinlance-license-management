# TLMS implementation status

**Last reviewed:** 2026-11-21 (main baseline `9c42f731c4a14445fc60e384ea6cf66b83aa3590`; PR #38 vendor-account resource policy present on main; exact-head/post-merge workflow evidence still requires verification)
**Current phase:** Phase 4 — identity, organizations and authorization
**Overall status:** Engineering foundation and initial identity/authorization slice implemented. TLMS is not production-ready; no complete licence lifecycle, payment, update distribution, SDK or deployment exists.

## Evidence-based phase status

| Phase | Scope | Status | Remaining exit evidence |
|---|---|---|---|
| 0 | Repository governance and project control | Initial governance documentation merged. Main branch currently reports `protected=false`; the connected GitHub integration cannot read admin-only protection settings. | Owner must enable PR-only changes, required CI checks, prevent force-push/deletion, resolve conversations, enable GitHub native secret scanning/push protection, dependency graph and Dependabot alerts; counsel/contributor rights review. |
| 1 | Requirements and architecture freeze | Initial ADRs, threat model, OpenAPI, authorization matrix, state machines, traceability and 31 decision records are present. | Complete semantic API authorization, lifecycle contracts, privacy/controller-processor/legal review, decision owners and explicit closure evidence. |
| 2 | Shared-hosting qualification | Requirements, provider shortlist and automated `tlms:hosting-preflight --json` runtime checks implemented; no provider approved and no live account tested. | Real annual cPanel provider PoC: PHP 8.3–8.5, intl, Sodium, Argon2id, MySQL 8.4 or MariaDB 10.11, outbound HTTPS, cron, webhooks, document-root isolation, permissions, resource limits and restore drill. |
| 3 | Engineering foundation and CI/CD | Laravel 13, locked Composer dependencies, PHP 8.3–8.5 CI, Pint, Composer audit, OpenAPI lint, MySQL 8.4.13 and MariaDB 10.11 integration, Semgrep (`p/security-audit`), Larastan 3.15.0 with PHPStan 2.3.7 (level 8 across `app/`, `src/`, `database/`, `routes/`, `bootstrap/`, `config/` and `tests/`; committed lock, no baseline), pinned Gitleaks 8.30.1 full-history scan across all reachable Git refs (`--all`) with an exact synthetic-fixture/path/rule allowlist, OSV-Scanner 2.7.1 Composer lockfile/repository scanning, weekly Composer/GitHub Actions Dependabot configuration, and a reproducible production-only cPanel ZIP builder/verifier are implemented. The artifact is CI-verified and is not published by default. | GitHub native secret scanning/push protection and Dependabot alert settings, enforced `main` ruleset, artifact install/upgrade/rollback automation, backup/restore automation and real cPanel PoC. |
| 4 | Identity, organizations and portals | ULID-backed user/vendor/customer schema, scoped permission catalogue, deny-by-default membership authorization, transactional allowlisted role assignment/revocation, immutable membership-role events, one-time browser-based Super Admin bootstrap and the initial authentication foundation are implemented. Fortify login/logout, email verification, password reset, TOTP, WebAuthn passkeys, MFA enrollment gating, password-confirmed MFA/recovery-code management, encrypted database sessions, fresh-database session-version enforcement and global session revocation are covered by feature tests. Password reset and session revocation are transactional; supported weaker legacy hashes are upgraded without downgrading stronger hashes. Public registration remains disabled. PR #29 adds a `CustomerOrganizationPolicy` that limits customer reads to the caller's own organization and vendor support reads to organizations under the caller's active vendor. PR #38 adds `VendorAccountPolicy::view` backed by the vendor-scoped `vendor.account.read` permission and database-reloaded membership authorization. These are narrow resource-policy slices, not complete ABAC. Maker-checker, privileged step-up, mutation policies, product/reseller resource policies, portals and comprehensive cross-tenant IDOR/concurrency coverage remain incomplete. | Complete resource-level ABAC for product/customer/vendor/reseller objects and every operation; maker-checker approvals; privileged step-up authorization; full administrator/customer/vendor portals; comprehensive cross-tenant IDOR/concurrency tests; stronger recovery-code verifier lifecycle; and end-to-end audit coverage. |
| 5 | Product, plans and entitlements | Not started. | Product registry, immutable plan versions, entitlement definitions/snapshots, upgrades/downgrades and entitlement contract tests. |
| 6 | Licence engine | Initial 256-bit licence-key and pepper-ring primitives plus domain normalization only. | Persisted issuance/verification, atomic activation allocation, lifecycle state machine, transfers, renewals, suspensions, revocation, trials, quotas, concurrency and recovery tests. |
| 7 | Cryptographic signing | Not started. | Ed25519 signing/verification, offline root custody, delegated operational keys, thresholds, rotation/revocation, independent verifier and cross-language vectors. |
| 8 | Activation and offline operation | Domain normalization primitive only. | Installation identity, domain binding, activation limits, signed 72-hour leases, refresh/grace, clock drift, outage, clone/restore and migration tests. |
| 9 | Payments and ledger | Not started. | Paystack/Flutterwave qualification, payment attempts/webhooks/idempotency, manual issuance, balanced append-only ledger, refunds, chargebacks, FX/tax and reconciliation. |
| 10 | Secure updates | Not started. | TUF root/targets/snapshot/timestamp metadata, key thresholds, signed artifacts, expiry, consistent snapshots, rollback/freeze protection and release tests. |
| 11 | PHP SDK and installer | Not started. | Framework-independent PHP SDK, signed offline verification, secure activation/cache, preflight installer and real cPanel install without SSH. |
| 12 | Talyvane integration | Not started. | End-to-end purchase → licence issuance → activation → renewal/revocation → update evidence. |
| 13 | Reseller, white-label and enterprise | Not started. | Reseller attribution/commission, branding, agency/enterprise entitlements, scoped staff and isolation tests. |
| 14 | Security, privacy and resilience | Initial threat model and local membership-role event evidence only. | Global hash-chained audit with external anchors, secrets/key custody, privacy export/deletion/retention, security assessment, incident response, backup restore, measured RPO/RTO and resilience tests. |
| 15 | Beta, launch and certification | Not started. | Operational runbooks, support, load/abuse testing, measured SLA, release checklist, full forensic audit and owner sign-off. |

No phase is declared 100% complete. Phase 0 and Phase 1 remain partially complete; Phase 2 still lacks a real provider PoC; Phase 3 remains an engineering foundation, not deployment-ready; Phase 4 now includes the initial authentication foundation but remains incomplete pending ABAC, step-up, maker-checker, portals and comprehensive tenant-isolation evidence.

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
