# Changelog

All notable changes to TLMS are recorded here. This file tracks repository engineering changes; it does not imply that a production release has been published.

## Unreleased

### Added
- Laravel 13 modular-monolith foundation with PHP 8.3–8.5 CI and MySQL 8.4 / MariaDB 10.11 integration coverage.
- Product-independent licensing-key, pepper-ring and domain-normalization primitives.
- ULID-backed vendor, user, customer organization, membership, role and permission schema.
- Deny-by-default, scope-aware membership authorization and transactional role assignment/revocation.
- Vendor-account read policy with a dedicated vendor-scoped permission, upgrade migration for existing system roles and cross-vendor/stale-membership regression coverage (PR #38).
- Tag-triggered release workflow that builds an SPDX 2.3 SBOM and creates/verifies GitHub/Sigstore SLSA provenance and SBOM attestations before publishing a version-tagged cPanel artifact (PR #36).
- Tenant-scoped maker-checker approvals for high-risk vendor role grants with fresh MFA, idempotency, expiry, beneficiary conflict-of-interest protection and atomic decision/role evidence (PR #47).
- Recent password confirmation for Super Admin session listing and global session revocation; protected-session invalidation for inactive accounts (PRs #49 and #51).
- Self-service MFA enrollment page available to all authenticated, verified active users while retaining secure transport, session-version and recent password-confirmation controls (PR #60).
- Self-only vendor/customer membership reads without granting collection listing or membership mutations (PR #50).
- Persisted customer-organization/vendor binding checks for vendor-support authorization; suspended organizations and stale tenant bindings are denied (PR #52).
- Tenant-admin vendor/customer membership revocation with fresh MFA step-up, immutable lifecycle evidence, idempotent retries, self-revocation denial and retained role history (PR #55).
- Expanded membership lifecycle regression coverage for invited-member cancellation, self-revocation denial, cross-organization isolation, suspended-vendor denial and immutable lifecycle-event evidence (PR #57).
- Serialized customer-membership revocation against concurrent parent-vendor suspension by locking and revalidating the persisted vendor row (PR #58).
- Vendor/customer membership invitations with hashed single-use tokens, HTTPS-only email links, expiry, resend, acceptance, verification and revocation invalidation; fresh MFA-gated inviter actions and session-confusion protection for account setup (PR #63).
- One-time initial Super Admin bootstrap with a 256-bit deployment secret, HTTPS/CSRF/rate-limit controls, atomic installation-state consumption and a system-attributed membership-role event (PR #20).
- Shared-hosting runtime preflight and reproducible production-only cPanel ZIP packaging with checksum/provenance verification (PRs #17 and #21).
- Fortify-based Super Admin authentication foundation: login/logout, verified email, password recovery, TOTP, WebAuthn passkeys, MFA enrollment gate, database-backed sessions, session revocation and recovery-code controls (PR #23).
- Pinned Gitleaks 8.30.1 scanning all reachable Git history and removed high-entropy synthetic password literals from authentication tests (PR #27).
- Semgrep, Larastan/PHPStan, Gitleaks and OSV-Scanner security gates; weekly Composer and GitHub Actions Dependabot update configuration.

### Security
- Invitation tokens are stored only as SHA-256 hashes; acceptance is single-use, expiry-checked and bound to the invited identity. Account setup rejects a different authenticated account, while verified existing users require same-account authentication and recent password confirmation.
- Bootstrap credentials are never persisted or flashed to browser input; successful setup requires removal of the environment secret and clearing cached configuration.
- MFA management and recovery-code retrieval require recent password confirmation; recovery codes are returned only after an explicit user action and are not embedded in rendered pages by default.
- Password resets and global session revocation execute in one database transaction; successful login upgrades supported weaker legacy password hashes to the configured stronger driver without downgrading stronger hashes.
- Production artifact verification rejects environment files, package-authentication files and private-key material.
- Pull-request CI artifacts are not cryptographically signed or published as releases. The tag-only signed release workflow has not yet been exercised with a real protected version tag; independent consumer verification remains outstanding.

### Not yet released
No production TLMS service or production software package is published. Generalized maker-checker and privileged step-up for operations beyond high-risk vendor role grants, resource-level ABAC, customer/vendor portals, product and licence lifecycle, payment processing, cryptographic signing, secure updates, customer SDK, Talyvane integration and live cPanel provider qualification remain in development.
