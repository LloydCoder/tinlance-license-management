# Security analysis and dependency governance

**Status:** Initial automated controls are implemented in GitHub Actions. Repository-level GitHub security settings and independent assurance remain separate release gates.

## Automated controls

| Control | Workflow behavior | Coverage and limitation |
|---|---|---|
| Semgrep Community Edition | `p/security-audit` runs on pushes, pull requests, weekly schedule and manual dispatch. Container image is pinned by digest. | SAST coverage depends on the Community ruleset; it is not equivalent to interprocedural commercial analysis or a penetration test. |
| Larastan/PHPStan | Larastan 3.15.0 and PHPStan 2.3.7 are exact-pinned in `composer.json` and `composer.lock`. `composer analyse` runs PHPStan level 8 with the Larastan Laravel extension across `app/`, `src/`, `database/`, `routes/`, `bootstrap/`, `config/` and `tests/`. | Static analysis is not runtime proof, penetration testing or a substitute for integration/security testing. No baseline or ignored-error file is used. |
| Gitleaks | Gitleaks Action is pinned to a full commit SHA, runs Gitleaks 8.30.1 and checks the full Git history (`fetch-depth: 0`). | A finding must be treated as a credential compromise: revoke/rotate first. Removing a string or rewriting history does not invalidate a leaked credential. |
| OSV-Scanner | OSV-Scanner 2.7.1 scans repository dependencies and the committed Composer lockfile on pushes, pull requests and the weekly schedule. | Uses OSV's public advisory database. It is independent of GitHub's dependency graph; it does not replace Composer audit or licensing review. |
| Composer audit | Existing CI runs `composer audit --locked`. | Covers known Composer advisories for the committed lockfile at scan time. |
| Dependabot | Weekly Composer and GitHub Actions update checks are configured in `.github/dependabot.yml`. | Dependabot alerts and security updates are separate repository settings and must be verified in GitHub. |

## Workflow security properties

- Third-party GitHub Actions are pinned to full commit SHAs.
- Checkout credentials are not persisted.
- Workflow permissions default to read-only; OSV-Scanner receives only `contents: read` and does not require GitHub dependency-graph or security-event write access.
- OSV-Scanner runs against the repository's resolved dependency lockfile and fails when known vulnerabilities are found.
- Gitleaks scans the complete available Git history and does not upload a SARIF artifact or post PR comments.
- Semgrep and Larastan/PHPStan level 8 fail the workflow on findings; no baseline or ignored-error suppression is configured.
- Security jobs are not treated as proof of production readiness.

## One-time Super Admin bootstrap security boundary

The initial bootstrap is a setup-only capability, not an authentication endpoint or a permanent administrator recovery path.

- The setup routes are available only while the primary installation row is unbootstrapped and a configured token matches the required base64url shape (43–128 characters; deployment instructions require at least 32 CSPRNG bytes).
- The submitted token is compared with `hash_equals`; it is never persisted, echoed in JSON, or flashed back as old form input.
- Setup responses use `no-store`, restrictive CSP, `no-referrer`, `nosniff` and frame-denial headers. HTTPS is mandatory outside local/testing; safe HTTP GET/HEAD requests redirect, insecure credential-bearing POSTs return 426 without redirect/replay, and secure responses set HSTS.
- POST requests are rate-limited and remain under Laravel's web middleware, including CSRF protection.
- A database transaction locks the single primary installation row before creating the initial vendor, administrator, membership and Super Admin role assignment. The grant is recorded as a system-attributed membership-role event in that same transaction; a second successful bootstrap must fail.
- The authorization catalogue is initialized only when empty and its exact vendor permission-name set is verified. Incomplete catalogues and cross-scope grants fail closed.
- The initial administrator email is not marked verified. Authentication, email verification, mandatory MFA enrollment, recovery and session controls are separate Phase 4 deliverables.
- The password policy accepts 15–1024 characters without character-composition requirements and accepts long passphrases. Breached-password screening remains an explicit authentication-phase requirement.

The design follows Laravel's request-forgery and rate-limiting middleware guidance and OWASP ASVS 5.0 authentication requirements. ASVS 6.2.5 advises against composition rules, and 6.2.1 strongly recommends a 15-character minimum.

## Platform access audit-chain integrity boundary (PR #80)

- Platform access events are appended in a single transaction with a monotonic sequence, predecessor hash, SHA-256 event hash and persisted chain head. Appends and verification serialize on the chain-head row.
- The operator command `tlms:audit:verify` checks sequence continuity, event contents, predecessor links, hash version and chain-head agreement. Existing events are backfilled deterministically during migration.
- Eloquent instance guards and MySQL/MariaDB BEFORE UPDATE and BEFORE DELETE triggers prevent ordinary application mutation of event rows.
- **Important limitation:** the event chain uses unkeyed SHA-256. It is tamper-evident against partial row edits, deletion/gaps, predecessor substitution and chain-head mismatch, but an administrator able to rewrite all event rows and the head can recompute the chain. It is not an independently anchored immutable log.
- Remaining hardening: external hash anchoring or asymmetric signing, separate append-only log storage/retention, independent monitoring and recovery/verification procedures. Do not describe the current implementation as HSM-backed, externally immutable or resistant to a fully privileged database administrator.

## Production artifact delivery boundary

The CI job builds and verifies the production-only cPanel ZIP on pushes and pull requests. It does not upload the package by default. Temporary Actions artifact upload requires an explicit manual workflow dispatch on `main` and expires after seven days. The ZIP carries a source/lockfile provenance manifest and SHA-256 checksum, but it is not cryptographically signed; checksum verification is not publisher authentication. Do not treat this package as a commercial deployment licence or as a signed release.

## Owner-controlled GitHub settings still required

The repository API currently reports `main.protected=false` and the repository rulesets endpoint returns an empty list. The connected GitHub integration cannot create rulesets or change admin-only security settings.

An owner with repository administration access must configure and verify:

1. A `main` branch ruleset requiring pull requests and successful CI and Security analysis checks; prohibit force pushes and deletion; require conversation resolution; avoid broad bypass actors.
2. GitHub native secret scanning and push protection, where available for the account and repository.
3. Dependency graph, Dependabot alerts and Dependabot security updates.
4. Code-scanning alert policy and review ownership.
5. Least-privilege GitHub Actions workflow permissions and approval rules for external actions.

Gitleaks in CI is useful detection, but it is not a substitute for native push protection or a branch ruleset. A successful workflow does not prove those account settings are enabled.

## Release gates not yet satisfied

- Secret scanning and push protection enabled in GitHub repository settings.
- Branch ruleset and required checks enforced on `main`.
- Dependency graph and Dependabot alert/security-update settings verified.
- Full application security assessment, threat-model validation, penetration test and cPanel provider qualification.

## Research references

- Larastan 3.15.0 supports PHPStan 2.3.5+ and Illuminate 13; its package metadata lists PHP ^8.2 and Laravel 13 support. TLMS pins Larastan 3.15.0 and PHPStan 2.3.7 in the committed Composer lock and sets `config.platform.php` to `8.3.0` so resolution respects the minimum supported runtime.
- OSV-Scanner supports Composer lockfiles and can scan resolved package versions without GitHub's dependency graph.
- GitHub rulesets support required pull requests, status checks, blocked force pushes, code scanning and secret-alert rules, but creating or editing them requires repository administration rights.

See the official project sources:
- https://github.com/larastan/larastan/releases/tag/v3.15.0
- https://packagist.org/packages/larastan/larastan
- https://github.com/gitleaks/gitleaks-action
- https://github.com/google/osv-scanner-action
- https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/available-rules-for-rulesets


## Version-2 customer profile payload commitments (PR #95)

- Customer-organization create/profile-update records use hash version 2 and include a unique details_ref_id plus details_hash in the canonical chain event.
- The referenced immutable profile event includes a SHA-256 change_digest over canonical before/after values for changed fields. Raw profile values are not copied into the audit chain or profile-event payload.
- Verification resolves the reference and checks the event type, organization, actor, tenant vendor, request ID, reason, changed fields and change digest before validating the chain hash.
- Historical version-1 events are verified using their original canonical form; version-2 verification does not silently reinterpret historical hashes.
- Mutation, profile event and chain append share one outer database transaction. The unique reference index prevents multiple chain entries from reusing one profile event.
- The digest is an unkeyed commitment, not encryption or a digital signature. The overall chain remains vulnerable to a privileged database administrator rewriting the complete database and recomputing all hashes. External anchoring and separate log retention remain open controls.


## Vendor profile administration and audit commitments (PR #97)

- Only active primary-vendor members with vendor.account.profile.manage can open the vendor profile surface. The permission is granted to vendor_admin; the bootstrap Super Admin is explicitly denied mutation even when its role catalogue includes the permission.
- The only mutable attribute is display_name. Legal name, installation key, account status, default currency and timezone are prohibited by request validation and are not mass-assigned.
- Writes require recent password confirmation and fresh five-minute TOTP/passkey step-up. The transaction locks the primary vendor and the caller's active membership, then reloads and rechecks authorization before mutation.
- A separate immutable vendor profile event stores the reason, changed-field list and a digest of canonical before/after values. A version-2 platform audit event references the event; verification checks actor, vendor/resource/tenant, request ID, event type, reason, changed fields and digest.
- Eloquent guards and MySQL/MariaDB BEFORE UPDATE/DELETE triggers protect the event table. Tests cover model-level and raw database mutation rejection.
- The payload digest and platform chain are unkeyed SHA-256. They detect partial/cross-record changes but do not prevent a privileged database administrator from rewriting all records and recomputing the chain. External anchoring and separate retention remain open controls.


## Product registry controls (PR #98)

- Products are tenant-owned by vendor_account_id. Vendor-scoped slug uniqueness is enforced in the database; a stable public UUID is distinct from the internal ULID.
- Product managers are scoped to products they created (initial assignment); vendor Super Admins can access all products in their own vendor. Cross-manager and cross-tenant reads are concealed as 404 responses.
- Product creation and draft updates require recent password confirmation and fresh five-minute TOTP/passkey step-up. The write transaction locks the vendor, then locks and revalidates the active vendor membership before mutation.
- Updates require expected_version and increment the version under row lock. Stale writes return 409. Vendor ownership, public ID, creator and lifecycle status are not mutable through the API.
- Product create/update events store the reason, changed fields and SHA-256 digest of canonical before/after values. The version-2 platform audit event references the immutable product event; verification cross-checks product, vendor/tenant, actor, event type, request ID and digest.
- Eloquent guards and MySQL/MariaDB BEFORE UPDATE/DELETE triggers protect product event rows. No product delete endpoint exists.
- The product digest and platform chain remain unkeyed SHA-256 and externally unanchored. They detect partial/cross-record tampering but do not prevent a privileged database administrator from rewriting the complete database and recomputing the chain. Explicit product assignments, release publication, approval controls and external anchoring remain open.
