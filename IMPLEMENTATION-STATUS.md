# TLMS implementation status

**Last reviewed:** 2026-11-20 (Laravel 13 runtime-security configuration increment)
**Current phase:** Phase 3 — engineering foundation and CI/CD
**Overall status:** Laravel 13 application bootstrap and PHP domain primitives implemented; no production licensing service, database schema or deployment exists.

## Evidence-based status

| Phase | Scope | Status | Exit evidence |
|---|---|---|---|
| 0 | Repository governance and project control | Documentation CI verified; owner governance still incomplete | Main branch protection is disabled in live GitHub metadata; owner security settings and legal rights review remain outstanding |
| 1 | Requirements and architecture freeze | Baseline documented; semantic authorization, lifecycle and external qualification gates remain | Operation-level authorization review, detailed lifecycle contracts, privacy/legal review, real hosting PoC and explicit decision closure |
| 2 | Shared-hosting qualification | Not started | Real provider proof-of-concept and written capability evidence |
| 3 | Engineering foundation and CI/CD | Laravel 13 bootstrap, committed Composer lock, Pint, Composer audit and PHP 8.3–8.5 CI verified; database, static analysis, security scanning and deployment artifact remain | MySQL 8.4 integration, static analysis/security gates, deployment artifact and shared-hosting PoC |
| 4 | Identity, organizations and portals | Not started | Authentication, authorization and isolation tests |
| 5 | Product, plans and entitlements | Not started | Versioned product and entitlement contract tests |
| 6 | Licence engine | Initial licence-key cryptographic primitive only; no lifecycle engine | Persisted key verifier/pepper-key ID, issuance transaction, activation/state machine, concurrency and database tests |
| 7 | Cryptographic signing | Not started | Independent verifier and cross-language test vectors |
| 8 | Activation and offline operation | Not started | Outage, lease expiry and migration tests |
| 9 | Payments and ledger | Not started | Webhook, reconciliation, manual issuance and ledger tests |
| 10 | Secure updates | Not started | TUF metadata and rollback/freeze rejection tests |
| 11 | PHP SDK and installer | Not started | Real cPanel installation without SSH |
| 12 | Talyvane integration | Not started | End-to-end purchase-to-install evidence |
| 13 | Reseller, white-label and enterprise | Not started | Tenant/role isolation and branding tests |
| 14 | Security, privacy and resilience | Not started | Security assessment, recovery exercise and privacy workflows |
| 15 | Beta, launch and certification | Not started | Release checklist, full forensic audit and production sign-off |

## Repository and CI evidence

- Repository: public `LloydCoder/tinlance-license-management`.
- Default branch: `main`.
- Architecture review branch: `chore/phase-1-contract-security-review`.
- Domain foundation branch: `feat/phase-3-domain-foundation`.
- Laravel foundation branch: `feat/phase-3-laravel-foundation` (stacked on the domain foundation branch).
- PR #1: https://github.com/LloydCoder/tinlance-license-management/pull/1 — merged into `main` at `d5fb57731c16128e35e5b251d7c4d2b3bfa0284c`.
- PR #3: https://github.com/LloydCoder/tinlance-license-management/pull/3 — merged into `main` at `1a446a743b4b877d32c1d28c6cc061dbf465f0b8` after exact-head CI passed.
- PR #4: https://github.com/LloydCoder/tinlance-license-management/pull/4 — open; Laravel foundation CI is green on the latest candidate, pending exact-head status-document reconciliation.
- PR #5: https://github.com/LloydCoder/tinlance-license-management/pull/5 — merged into `main` at `2ed9198098d9ba2ae6ce030dbada46fb2f52bb29`.
- PR #6: https://github.com/LloydCoder/tinlance-license-management/pull/6 — draft identity/authorization increment, not merged.
- Main branch protection could not be verified through the current GitHub integration (the branch-protection endpoint returned 403; an empty rulesets response does not establish the state of legacy branch protection). The owner must verify Settings → Branches and Settings → Code security and analysis.
- PHP domain code exists under `src/Domain/Licensing/` and is Composer-autoloaded alongside the Laravel 13 application bootstrap. It is not yet persisted or exposed through business APIs or SDKs.

## Approved platform authority and credential requirements

- ADR 0003 and `docs/requirements/SUPER-ADMIN-REQUIREMENTS.md` define platform-wide Super Admin authority and the centralized Control Center. This is approved architecture, not implemented functionality.
- ADR 0002 and the integration credential requirements/security documents define Super Admin-managed provider credentials, encrypted storage, write-only secret fields, validation, rotation and redaction. This is approved architecture, not implemented functionality.
- Signing root keys remain separate from ordinary dashboard credentials.

## Phase 1 architecture artifacts committed

- ADR 0004 — initial vendor-operated deployment and tenancy model.
- ADR 0005 — initial technical baseline: Laravel/PHP matrix, MySQL/MariaDB policy, ULIDs, key/token formats, domain normalization, offline lease defaults, audit anchoring and TUF profile.
- `docs/architecture/AUTHORIZATION-MATRIX.md` — actor classes, permissions, scopes and step-up policy.
- `docs/architecture/DOMAIN-MODEL.md` — conceptual aggregates, ownership, relationships and invariants.
- `docs/security/THREAT-MODEL.md` — trust boundaries, assets, STRIDE-oriented threat register and shared-hosting residual risks.
- `docs/architecture/API-CONTRACTS.md` — API conventions, authentication profiles, idempotency and SDK/webhook contracts.
- `docs/api/openapi.yaml` — OpenAPI 3.1.1 v1 draft.
- `docs/architecture/REQUIREMENTS-TRACEABILITY.md` — requirement-to-artifact and future test mapping.
- `docs/architecture/OPEN-DECISIONS.md` — 30 explicit decisions; DEC-026 now records the CI-integrated Redocly validator, and DEC-030 separates TUF trust-threshold/key custody from metadata role design.
- `docs/architecture/API-SECURITY-REVIEW.md` — existing API operation authorization register, webhook invariants and missing contract surface.
- `docs/architecture/STATE-MACHINES.md` — licence, payment, credential, release and subscription lifecycle baselines.
- `docs/security/PRIVACY-AND-DATA-GOVERNANCE.md` — classification, privacy workflows and provisional retention schedule.
- `docs/operations/SHARED-HOSTING-OPERATIONS.md` — provider qualification, synchronous/recoverable processing, backup and recovery baseline.
- `docs/operations/CPANEL-PROVIDER-QUALIFICATION.md` — researched cPanel shortlist and mandatory live proof-of-concept; no provider is approved for annual purchase yet.

These documents are an initial architecture baseline. Redocly CLI 1.34.24 OpenAPI lint is integrated in CI; this is structural/lint evidence, not runtime contract or authorization proof. Phase 1 is not complete until operation permissions are normalized, lifecycle transition contracts and tests are complete, privacy/legal roles and retention are reviewed, a real cPanel host is qualified, recovery is exercised, and all remaining decisions have owners and acceptance evidence.

## Initial Phase 3 implementation evidence

- `composer.json` establishes PHP 8.3–8.5 and PSR-4 namespace mapping.
- `tests/run.php` and `tests/bootstrap.php` provide a dependency-free test harness while the framework foundation is being established.
- `src/Domain/Licensing/LicenseKey.php` issues 256-bit random keys and computes version-bound HMAC-SHA-256 verifiers using a separate pepper.
- `src/Domain/Licensing/DomainName.php` canonicalizes domains using UTS #46 IDNA processing, lowercase ASCII, one terminal-dot removal, and strict rejection of URL syntax, credentials, ports, wildcards, invalid labels and IP literals. PHP intl is now mandatory for this operation.
- `src/Domain/Licensing/IssuedLicenseKey.php` returns the plaintext once alongside verifier and pepper key ID.
- `src/Domain/Licensing/LicenseKeyPepperRing.php` verifies using the stored pepper ID and returns a replacement verifier under the active pepper only after successful verification; persistence remains the caller's transactional responsibility.
- `src/Domain/Licensing/LicenseKeyVerificationResult.php` carries the verification result and optional re-key material.
- `tests/Unit/Domain/LicenseKeyTest.php` covers issuance, format/entropy length, successful verification, tampering, wrong pepper, malformed input, pepper/key-ID validation and uniqueness.
- `tests/Unit/Domain/LicenseKeyPepperRingTest.php` covers old-pepper verification/re-key, current-pepper no-op, unknown key IDs, missing active pepper and tampering.
- `tests/Unit/Domain/DomainNameTest.php` covers case/trailing-dot normalization, IDN conversion and rejection of schemes, paths, ports, credentials, queries, wildcards, whitespace and invalid labels.
- `docs/security/LICENCE-KEY-VERIFICATION.md` defines pepper rotation constraints, including why old peppers must remain available until old verifiers are re-keyed on successful key presentation.
- `docs/security/DOMAIN-NAME-NORMALIZATION.md` defines canonical IDNA, input rejection, environment policy and cross-language test-vector requirements.
- CI includes PHP 8.3/8.4/8.5 matrix testing, intl, Composer validation, PHP syntax linting and the domain test runner. Checkout v5 and setup-php 2.40.0 are pinned to immutable SHAs.
- Latest fully green baseline run `44817157192` passed all 20 then-registered test methods on PHP 8.3, 8.4 and 8.5, along with PHP syntax lint, Composer manifest validation, documentation governance and OpenAPI lint. This does not cover the later pepper-ring test addition.
- Earlier candidate run `44817454362` failed because the licence-key verifier regular expression was over-escaped. The defect was corrected in commit `b47c173351165acc1a58193691efb6475e90edf1`; CI run `44818887629` then passed the 25-test domain suite and the PHP 8.3/8.4/8.5 matrix.
- Laravel bootstrap CI run `44820062641` completed successfully on candidate commit `d6c109c8c705ee1f6be65b3a6d845998d2e28c0a`. It passed Composer validation and locked installation, Composer audit (no advisories), PHP syntax lint, Pint, all 25 domain tests, two Laravel feature tests, Artisan bootstrap, documentation governance and OpenAPI validation on PHP 8.3, 8.4 and 8.5. Subsequent documentation-only commits require their own CI run before the updated branch is considered verified.

## Laravel 13 application foundation evidence

- Branch: `feat/phase-3-laravel-foundation`, stacked on `feat/phase-3-domain-foundation`; no PR has been merged.
- `composer.json` now requires Laravel Framework `^13.17`, PHP 8.3–8.5, PHPUnit and Laravel Pint. `composer.lock` is committed and locks Laravel Framework v13.43.0, PHPUnit 12.5.45 and Pint 1.32.3.
- Laravel application entry points exist at `artisan`, `bootstrap/app.php`, `bootstrap/providers.php` and `public/index.php`; application code uses the App namespace while the Tlms domain namespace is preserved.
- Runtime configuration defaults to MySQL, file-backed encrypted sessions, file cache and synchronous queue processing. Session serialization uses JSON; production environment requires HTTPS, APP_DEBUG=false, a unique APP_KEY and SESSION_SECURE_COOKIE=true.
- No default users, organizations, tenants or migrations were added. Identity and authorization schema must be designed with ULID and isolation invariants before persistence.
- `tests/Feature/HealthCheckTest.php` contains two Laravel feature tests; `tests/Feature/RuntimeSecurityConfigurationTest.php` covers secure/encrypted/HttpOnly/SameSite session configuration and the application timezone default. The framework suite currently contains four tests (8 assertions). The retained domain test runner executes 25 test methods.
- `composer test`, `composer test:framework`, `composer format:check`, `composer audit --locked` and PHP syntax lint are integrated into the PHP 8.3/8.4/8.5 CI matrix.
- One-time dependency-lock and formatting bootstrap workflows were removed after generating the lockfile and applying Pint. Ordinary CI uses the committed lock and does not run composer update.
- `docs/architecture/LARAVEL-APPLICATION-FOUNDATION.md` records runtime boundaries, secrets policy and remaining acceptance gates.

## Phase 0 remaining blockers

1. Verify main branch protection, required pull-request review policy and required status checks through an owner-authorized GitHub session.
2. Confirm secret scanning, push protection and dependency review settings.
3. Review commercial rights notice, contributor ownership and dependency licensing with qualified counsel before external sale.
4. Re-run all required CI checks on the final Phase 0 candidate commit and complete forensic review.
5. PR #1 is merged. Owner-level branch protection, required checks, secret scanning/push protection and dependency review remain unconfigured or unverified and must be completed before production release.

## Phase 1 remaining work

1. Expand licence, payment, credential and release lifecycle contracts into actor/precondition/transaction/side-effect/idempotency test specifications (baseline expansion is now recorded in STATE-MACHINES.md).
2. OpenAPI 3.1.1 lint is integrated; complete semantic schema, authorization, idempotency and compatibility review; add executable contract tests with the application.
3. Complete privacy/data classification, retention/deletion/export and data-processing requirements.
4. Execute the real-account cPanel proof-of-concept in `CPANEL-PROVIDER-QUALIFICATION.md`; public provider pages do not prove database versions, PHP extensions, cron cadence, egress, webhooks or recovery.
5. Reconcile every requirement ID with API operation, domain entity, threat and future automated test.
6. Record unresolved choices and obtain architecture sign-off before production deployment. The current isolated domain slice proceeds on a feature branch without claiming architecture freeze.

## Completion policy

A phase is complete only when deliverables are committed and reviewed; required tests execute against intended runtimes and dependencies; all required CI jobs pass on the exact candidate commit; a phase-specific forensic review checks code, tests, documentation, security and traceability; remaining risks are closed or explicitly accepted; and evidence links, commit SHA and review date are recorded here.

Never report 100% completion while a required check is missing, skipped, inconclusive or failing.
