# TLMS implementation status

**Last reviewed:** 2026-11-20 (Phase 3 domain foundation increment)
**Current phase:** Phase 3 — engineering foundation and CI/CD
**Overall status:** Initial PHP domain foundation implemented and under CI verification; no production application or deployment exists.

## Evidence-based status

| Phase | Scope | Status | Exit evidence |
|---|---|---|---|
| 0 | Repository governance and project control | Documentation CI verified; owner governance still incomplete | Main branch protection is disabled in live GitHub metadata; owner security settings and legal rights review remain outstanding |
| 1 | Requirements and architecture freeze | Baseline documented; semantic authorization, lifecycle and external qualification gates remain | Operation-level authorization review, detailed lifecycle contracts, privacy/legal review, real hosting PoC and explicit decision closure |
| 2 | Shared-hosting qualification | Not started | Real provider proof-of-concept and written capability evidence |
| 3 | Engineering foundation and CI/CD | In progress — Composer manifest, framework-independent PHP autoloading/test runner, PHP 8.3–8.5 CI matrix and first domain primitive added | Laravel 13 bootstrap, dependency lock, static analysis, formatting, security scanning, DB matrix and deployment artifact |
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
- Engineering branch: `feat/phase-3-domain-foundation`.
- PR #1: https://github.com/LloydCoder/tinlance-license-management/pull/1 — open and unmerged.
- Latest Phase 0 CI candidate before Phase 1 documentation: commit `a021bdabc9d89d9b962cded7173de42a285a3cba`.
- GitHub Actions push run `44811522610`: completed successfully.
- GitHub Actions pull-request run `44811528330`: completed successfully.
- Live GitHub branch metadata reports `main.protected=false` and required status checks disabled. The repository owner can configure protection independently; engineering work continues on feature branches.
- Initial PHP domain code now exists under `src/Domain/Licensing/`; it is not yet integrated with Laravel, persistence, API endpoints or SDKs.

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
- `tests/Unit/Domain/LicenseKeyTest.php` covers issuance, format/entropy length, successful verification, tampering, wrong pepper, malformed input, pepper/key-ID validation and uniqueness.
- `tests/Unit/Domain/DomainNameTest.php` covers case/trailing-dot normalization, IDN conversion and rejection of schemes, paths, ports, credentials, queries, wildcards, whitespace and invalid labels.
- `docs/security/LICENCE-KEY-VERIFICATION.md` defines pepper rotation constraints, including why old peppers must remain available until old verifiers are re-keyed on successful key presentation.
- `docs/security/DOMAIN-NAME-NORMALIZATION.md` defines canonical IDNA, input rejection, environment policy and cross-language test-vector requirements.
- CI includes PHP 8.3/8.4/8.5 matrix testing, intl, Composer validation, PHP syntax linting and the domain test runner. Checkout v5 and setup-php 2.40.0 are pinned to immutable SHAs.
- Latest CI run 44816764710 passed documentation governance, OpenAPI lint and all PHP jobs. The previous green run passed 9 licence-key tests plus 8 domain-name tests on each PHP runtime. Three additional domain-name edge-case tests were then added (20 test methods total); the latest candidate must rerun CI before those additions are considered verified.

## Phase 0 remaining blockers

1. Verify main branch protection, required pull-request review policy and required status checks through an owner-authorized GitHub session.
2. Confirm secret scanning, push protection and dependency review settings.
3. Review commercial rights notice, contributor ownership and dependency licensing with qualified counsel before external sale.
4. Re-run all required CI checks on the final Phase 0 candidate commit and complete forensic review.
5. Merge PR #1 only after governance gates are verified. This does not block isolated feature-branch engineering.

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
