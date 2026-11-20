# TLMS implementation status

**Last reviewed:** 2026-11-20 (membership role assignment and Argon2id baseline)
**Current phase:** Phase 4 — identity, organizations and authorization
**Overall status:** Engineering foundation and initial identity/authorization slice implemented. TLMS is not production-ready; no complete licence lifecycle, payment, update distribution, SDK or deployment exists.

## Evidence-based phase status

| Phase | Scope | Status | Remaining exit evidence |
|---|---|---|---|
| 0 | Repository governance and project control | Initial governance documentation merged. Main branch currently reports `protected=false`; the connected GitHub integration cannot read admin-only protection settings. | Owner must enable PR-only changes, required CI checks, prevent force-push/deletion, resolve conversations, enable secret scanning/push protection and dependency review; counsel/contributor rights review. |
| 1 | Requirements and architecture freeze | Initial ADRs, threat model, OpenAPI, authorization matrix, state machines, traceability and 31 decision records are present. | Complete semantic API authorization, lifecycle contracts, privacy/controller-processor/legal review, decision owners and explicit closure evidence. |
| 2 | Shared-hosting qualification | Requirements and candidate shortlist documented; no provider approved. | Real annual cPanel provider PoC: PHP 8.5, intl, Sodium, Argon2id, MySQL 8.4, outbound HTTPS, cron, webhooks, document-root isolation, permissions, resource limits and restore drill. |
| 3 | Engineering foundation and CI/CD | Laravel 13, locked Composer dependencies, domain primitives, PHP 8.3–8.5 CI, Pint, Composer audit, OpenAPI lint and MySQL 8.4.13 integration are verified. | Larastan/PHPStan, Semgrep/CodeQL and secret/dependency scanning; reproducible production artifact, install/upgrade/rollback scripts, backup/restore automation and host PoC. |
| 4 | Identity, organizations and portals | ULID-backed user/vendor/customer schema, scoped permission catalogue, deny-by-default membership authorization, transactional allowlisted role assignment/revocation and immutable membership-role event models implemented. | One-time Super Admin bootstrap; login/logout, email verification, password recovery, session revocation, WebAuthn/TOTP/recovery, rate limits, step-up/MFA, maker-checker, resource-level ABAC, portals and cross-customer IDOR/concurrency tests. |
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

No phase is declared 100% complete. Phase 0 and Phase 1 remain partially complete; Phase 3 is an engineering foundation, not deployment-ready; Phase 4 is an initial authorization slice.

## Repository and pull-request history

- Public repository: [LloydCoder/tinlance-license-management](https://github.com/LloydCoder/tinlance-license-management).
- Default branch: `main`.
- PR #1 — governance baseline: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/1), merge commit `d5fb57731c16128e35e5b251d7c4d2b3bfa0284c`.
- PR #2 — Phase 1 contract/security review: closed as merged into its working branch; the resulting architecture material is reconciled in the merged documentation and current mainline.
- PR #3 — PHP domain foundation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/3), merge commit `1a446a743b4b877d32c1d28c6cc061dbf465f0b8`.
- PR #4 — Laravel 13 foundation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/4), merge commit `f7f2ed5fa71ad7bbffc406e5736f42b7db5ce093`.
- PR #5 — Phase 1 API security/contract documentation: [merged](https://github.com/LloydCoder/tinlance-license-management/pull/5), merge commit `2ed9198098d9ba2ae6ce030dbada46fb2f52bb29`.
- PR #6 — identity and membership authorization: [open, draft](https://github.com/LloydCoder/tinlance-license-management/pull/6). It must remain unmerged until the final documentation commit's exact-head CI is green and review is complete.

## Latest verified engineering evidence

CI run [44825741658](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44825741658) passed on PR #6 candidate head `b9b316f69103ed4ecc302f8433dda0d73f3f7806`:
- PHP 8.3, 8.4 and 8.5 jobs passed.
- 25 framework-independent domain test methods passed.
- 47 Laravel feature tests passed (91 assertions).
- The same 47 Laravel feature tests passed against MySQL 8.4.13; the integration job asserts that MySQL is the active driver.
- Composer validation, locked installation and `composer audit --locked` passed with no advisories.
- Laravel Pint passed across 49 PHP files.
- PHP syntax lint, Artisan bootstrap, documentation governance and OpenAPI 3.1.1 validation passed.
- Password tests verify Argon2id with at least 19 MiB memory, two iterations and one lane; the explicit bcrypt fallback rejects inputs above 72 bytes.

This run predates the status/README/decision-record reconciliation in the current PR branch. The resulting new exact head must receive a fresh full CI run before PR #6 is merged.

## Implemented identity and authorization boundaries

- Migrations cover vendor accounts, users, password reset tokens, roles, scoped permissions, vendor/customer memberships, membership-role pivots and membership-role event tables.
- All primary identity/organization IDs use ULIDs. User email is trimmed/lowercased and uniquely indexed; password storage uses Laravel's hashed cast.
- Argon2id is the default password hash (19 MiB, 2 iterations, 1 lane); configuration cannot lower the minimum. Bcrypt is an explicit non-default fallback with cost at least 12 and a 72-byte input ceiling.
- Vendor and customer memberships are separate authorization contexts. Permission rows carry explicit vendor/customer scope.
- MembershipAuthorization re-reads actor/resource status and membership state from the database. Unknown permissions, stale suspended models, missing membership and cross-scope role/permission grants deny by default.
- MembershipRoleAssignmentService uses DB transactions and row locks; requires active actor/resource/target, actor permission, same scope, non-self target and non-empty reason. It allowlists low-risk roles, blocks generic grants of Super Admin/platform admin/finance admin/product manager/customer owner, supports idempotent assignment/revocation and records a membership-role event in the same transaction.
- Membership-role event models reject Eloquent instance updates/deletes. This is application-level append-only evidence only; it is not the global hash-chained audit ledger or a DB-level tamper-proof guarantee.
- No login/MFA/bootstrap endpoints, resource-level ABAC, global audit chain or business API is exposed yet.

## Owner-level release blockers

1. Configure and verify branch protection and required checks on `main`; the current branch metadata reports `protected=false`.
2. Enable secret scanning/push protection and dependency review through repository settings.
3. Complete counsel review of source-visible commercial licensing, contributor rights, trademarks, privacy roles and target-market payment compliance.
4. Qualify a real annual cPanel provider and complete backup/restore and operational recovery exercises.
5. Do not publish production SLAs or purchase annual hosting based only on provider marketing claims.
