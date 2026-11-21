# Phase 0–3 forensic engineering acceptance audit

**Audit date:** 2026-11-21  
**Audited baseline:** `main` at `4e7d45c129594f60df50b1aeb108a2cfe633f786`  
**Repository:** [LloydCoder/tinlance-license-management](https://github.com/LloydCoder/tinlance-license-management)  
**Result:** The current mainline CI and Security workflows are green. Phases 0–3 are **not accepted as complete**. Each has implementation evidence and outstanding acceptance gates below.

This audit distinguishes repository evidence from owner, legal, provider and production-environment evidence. A document, CI simulation, public plan page or passing workflow is not a substitute for the real-world acceptance test it describes.

## 1. Current repository and workflow state

- Repository is public, non-archived and uses `main` as its default branch.
- Current audited main head: `4e7d45c129594f60df50b1aeb108a2cfe633f786`.
- [Main CI run 44880367359](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44880367359): **8/8 jobs passed** — PHP 8.3, 8.4 and 8.5; MySQL 8.4; MariaDB 10.11; cPanel artifact verification; documentation governance; OpenAPI validation.
- [Main Security analysis run 44880367355](https://github.com/LloydCoder/tinlance-license-management/actions/runs/44880367355): **4/4 jobs passed** — Larastan, Semgrep, Gitleaks full-history scan and OSV-Scanner.
- No open pull requests and no open issues were returned by the repository searches at audit time.
- PR #34 was closed without merge because its branch diverged from current main (65 commits ahead, 8 behind) and reintroduced an older authentication implementation with known CI/SAST issues. The current mainline already contains the authentication work through PR #23 and subsequent fixes. PR #34's failed checks included SQLite migration/session setup errors, two synthetic test-password findings in Gitleaks, and a nullable session-version access finding in Larastan. Do not merge or revive that stale branch; current mainline is authoritative.

## 2. Phase 0 — Repository governance and project control

**Status: PARTIALLY IMPLEMENTED — OWNER AND LEGAL ACCEPTANCE REQUIRED**

### Verified in the repository

- Public visibility and reserved-rights commercial model are documented in `LICENSE` and `COMMERCIAL-LICENSING.md`. The repository explicitly does not claim to be open source and separates evaluation, internal deployment, production use, white-label, redistribution and sublicensing permissions.
- `TRADEMARKS.md` reserves marks and requires clearance before launch.
- `CONTRIBUTING.md` says external code contributions are not yet accepted pending a reviewed contributor-rights policy.
- `SECURITY.md`, ADRs, roadmap, risk/requirements records and `docs/architecture/DEFINITION-OF-DONE.md` exist.
- CI pins GitHub Actions by immutable commit SHA and security container images by digest.
- Security workflow runs Semgrep, full reachable-history Gitleaks and OSV-Scanner. Dependabot Composer and GitHub Actions update configuration is committed.

### Unresolved mandatory gates

1. **Main branch governance:** the repository rulesets endpoint returned an empty list. The branch-protection endpoint returned HTTP 403 to the connected GitHub integration, so the effective branch-protection configuration cannot be independently read here. A previous connector snapshot reported `protected=false`; treat this as an unresolved owner-level blocker, not as proof of the current effective settings. The owner must create and verify a `main` ruleset requiring pull requests and the CI/Security checks, blocking force-push and deletion, and defining the bypass policy.
2. **Native security settings:** native secret scanning, push protection, dependency graph/Dependabot alerts and any available code-scanning defaults have not been independently verified through this connection. Gitleaks/OSV CI do not replace GitHub-native controls.
3. **Legal rights:** qualified counsel must review the reserved-rights licence, ownership chain, third-party notices, contribution/CLA or DCO policy, customer agreements, warranty/liability terms and white-label/redistribution terms. No legal approval is evidenced.
4. **Trademark clearance:** target-market search, ownership/registration evidence and permitted-use review remain open.

### Acceptance evidence still required

- Screenshot/API evidence of the enforced `main` ruleset and its required check names, with no broad bypass.
- Verified repository security/analysis settings.
- Counsel-approved rights and customer documents, plus documented contributor-rights process.
- Trademark clearance decision and evidence.

## 3. Phase 1 — Requirements discovery and architecture freeze

**Status: DOCUMENTED BASELINE — ARCHITECTURE EXIT CRITERIA NOT CLOSED**

### Verified in the repository

- ADRs 0001–0007 record public/commercial rights, credential authority, Super Admin control, initial deployment/tenancy, technical baseline, MariaDB/cPanel compatibility and one-time bootstrap.
- Architecture artifacts cover domain model, identity schema, authorization matrix, state machines, API contracts, OpenAPI 3.1.1, API security review, threat model, privacy/data governance, requirements traceability, shared-hosting operations and unresolved decisions.
- The architecture baseline selects a single self-hosted vendor installation first; hosted multi-tenancy is deferred.
- The API contract linter is enforced in CI. The API security review explicitly says lint does not prove runtime authorization, idempotency, raw webhook verification, transaction boundaries or response redaction.
- The decision register makes unresolved choices visible rather than silently treating them as accepted.

### Unresolved mandatory gates

1. Complete the API operation matrix: actor class, authentication scheme, permission, object scope, step-up requirement, audit event, idempotency/replay policy, data classification and positive/negative tests for every operation.
2. Resolve material lifecycle ambiguities in `STATE-MACHINES.md` before their corresponding services are implemented: licence transfer/replacement/reinstatement, payment settlement/refund/chargeback, credential rotation, TUF release recovery and subscription transitions.
3. Convert requirements into executable acceptance tests as the corresponding code is built; traceability documents alone are not implementation evidence.
4. Obtain legal decisions on controller/processor roles, lawful basis, retention periods, data-subject workflows, subprocessors, cross-border transfers, privacy notices and DPAs.
5. Approve cryptographic trust thresholds, root/delegated key custody ceremonies and compromise recovery in a dedicated ADR before production signing.

### Acceptance evidence still required

- Signed architecture/requirements approval by the accountable product and engineering owners.
- Completed operation-by-operation API security review and executable contract tests.
- Closed or explicitly risk-accepted decision register entries with owner, rationale and evidence.
- Counsel-approved privacy/contract decisions for the intended deployment jurisdictions.

## 4. Phase 2 — Shared-hosting qualification

**Status: PREPARATION IMPLEMENTED — REAL PROVIDER PoC NOT STARTED/NOT VERIFIED**

### Verified in the repository

- The mandatory deployment target is annual-prepaid cPanel/shared hosting.
- The architecture does not require VPS, root, Docker, Redis, persistent workers, Node.js at runtime or mandatory SSH.
- `tlms:hosting-preflight --json` checks PHP version, required extensions, Argon2id configuration, database vendor/version, production environment, debug state, application key, session security, writable storage and document-root isolation.
- Provider qualification and shared-hosting operations documents specify cron, HTTPS egress/webhooks, resource limits, source isolation, independent backups, restore drills, monitoring and migration requirements.
- MySQL 8.4 and MariaDB 10.11 are exercised in CI. This proves test-environment database compatibility for the tested suite, not compatibility with an actual hosting provider.

### Unresolved mandatory gates

1. Select a specific annual cPanel provider/plan only after written confirmation of the required PHP selector and CLI/web runtime, PHP extensions, Argon2id, Sodium, exact SQL engine/version, outbound HTTPS, inbound webhooks, cron interval, quotas, document-root configuration, backup/export and acceptable-use terms.
2. Obtain and use a real test account. Execute the application deployment and migrations through the intended cPanel workflow, not just CI.
3. Prove five-minute cron triggering, missed-run catch-up, overlap prevention and idempotent processing under provider limits.
4. Prove inbound webhook delivery and outbound HTTPS to the required integrations.
5. Prove application source, `.env`, logs, backups and private material remain outside the public document root.
6. Perform independent encrypted backup and restore drills; measure RPO/RTO. The current 15-minute RPO / 4-hour RTO values are targets, not achieved service levels.

### Acceptance evidence still required

- Written provider response and selected plan/renewal terms.
- Redacted account runtime report with PHP/extension/SQL versions and quotas.
- Successful install, migration, scheduler, webhook, egress and isolation test results.
- Backup/restore drill report with measured RPO/RTO and tested migration/export path.

## 5. Phase 3 — Engineering foundation and CI/CD

**Status: ENGINEERING FOUNDATION IMPLEMENTED — RELEASE/OPERATIONS GATES OPEN**

### Verified in the repository

- Laravel 13 application foundation, Composer manifest/lock and PHP 8.3–8.5 compatibility matrix.
- MySQL 8.4 and MariaDB 10.11 integration jobs.
- Pint formatting, PHPUnit framework tests, framework-independent domain tests, Larastan/PHPStan, Composer audit, OpenAPI lint, Semgrep, full-history Gitleaks and OSV-Scanner.
- Dependabot configuration for Composer and GitHub Actions.
- GitHub Actions and security container references are pinned to immutable SHAs/digests.
- A production-only cPanel ZIP builder and verifier check required/forbidden package contents, dependency exclusions, checksum, source/lock manifest metadata and byte-for-byte repeatability within the pinned CI toolchain.

### Unresolved mandatory gates

1. Native repository governance/security settings in Phase 0 must be verified.
2. The current ZIP is **not cryptographically signed**. Its source commit and lockfile digest manifest are useful traceability, not independently authenticated build provenance.
3. SBOM generation, provenance attestation, artifact signature and independent signature verification are not implemented as a complete release trust chain.
4. Install, upgrade, rollback and interrupted-upgrade recovery procedures are not automated or tested on a real cPanel account.
5. Backup/restore automation and recovery exercises remain open.
6. Production artifact retrieval is manual/explicit; no release publication or deployment is claimed.
7. The cPanel package workflow is a CI artifact-content/reproducibility check, not proof that a real provider can run the application.

### Acceptance evidence still required

- Signed artifact, SBOM, provenance and independent verification tests.
- Repeatable install/upgrade/rollback/recovery automation compatible with cPanel File Manager/FTP and without mandatory SSH.
- Automated backup/restore plus a real restore drill.
- End-to-end provider qualification from Phase 2.

## 6. Cross-phase dependency and release blockers

| Blocker | Phase(s) | Can be completed in repository CI alone? | Required owner/evidence |
|---|---:|---|---|
| Enforced `main` ruleset and required checks | 0 | No | Repository owner; settings evidence |
| GitHub native secret/push protection and Dependabot alerts | 0, 3 | No | Repository owner; security settings evidence |
| Counsel review of commercial/contributor/privacy/customer terms | 0, 1 | No | Qualified counsel; approved documents |
| Trademark clearance | 0 | No | Counsel/brand owner; clearance record |
| Complete API operation security mapping | 1 | Partly | Engineering owner; operation matrix and tests |
| Final retention/controller/processor decisions | 1 | No | Counsel/privacy owner; jurisdictional decision |
| Production signing trust model and key ceremony | 1, 7, 10 | Partly | Security owner; ADR, ceremony and recovery drill |
| Real annual cPanel provider qualification | 2 | No | Operations owner; actual account evidence |
| Signed release, SBOM/provenance and install/rollback | 3, 10, 11 | Partly | Engineering/release owner; reproducible verification |
| Backup/restore automation and measured RPO/RTO | 2, 3, 14 | No | Operations owner; restore-drill evidence |

## 7. Research references checked on 2026-11-21

- Laravel 13 deployment requirements: https://laravel.com/docs/13.x/deployment — PHP 8.3 minimum and required PHP extensions.
- PHP supported versions: https://www.php.net/supported-versions.php — PHP 8.3 is in security-only support through 2027-12-31; PHP 8.4 active support through 2026-12-31; PHP 8.5 active support through 2027-12-31. Keep the compatibility floor deliberate and review it at release gates.
- cPanel MultiPHP Manager: https://docs.cpanel.net/cpanel/software/multiphp-manager-for-cpanel/ — EasyApache 4 supports PHP 8.1 through 8.5 on supported systems; this does not prove a particular shared plan exposes PHP 8.5.
- GitHub ruleset controls: https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets — required status checks, PR requirements, force-push/deletion controls and bypass policy.
- GitHub security settings: https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/enabling-features-for-your-repository/managing-security-and-analysis-settings-for-your-repository — public visibility alone does not prove that push protection or all security controls are enabled.

## 8. Audit conclusion

The current codebase has a substantial and green engineering foundation. It is not accurate to declare Phases 0, 1, 2 or 3 complete. Phase 0 has owner/legal configuration blockers; Phase 1 has architecture approval and semantic/API/privacy closure gaps; Phase 2 lacks a real provider account proof-of-concept; Phase 3 lacks a signed/provenanced release chain, automated install/upgrade/rollback and tested backup/restore.

No production readiness, legal compliance, cPanel qualification, SLA, cryptographic trust ceremony or 100% completion is claimed by this audit. Downstream implementation must continue only against the accepted requirements and preserve the shared-hosting constraints.
