# Changelog

All notable changes to TLMS are recorded here. This file tracks repository engineering changes; it does not imply that a production release has been published.

## Unreleased

### Added
- Laravel 13 modular-monolith foundation with PHP 8.3–8.5 CI and MySQL 8.4 / MariaDB 10.11 integration coverage.
- Product-independent licensing-key, pepper-ring and domain-normalization primitives.
- ULID-backed vendor, user, customer organization, membership, role and permission schema.
- Deny-by-default, scope-aware membership authorization and transactional role assignment/revocation.
- One-time initial Super Admin bootstrap with a 256-bit deployment secret, HTTPS/CSRF/rate-limit controls, atomic installation-state consumption and a system-attributed membership-role event (PR #20).
- Shared-hosting runtime preflight and reproducible production-only cPanel ZIP packaging with checksum/provenance verification (PRs #17 and #21).
- Fortify-based Super Admin authentication foundation: login/logout, verified email, password recovery, TOTP, WebAuthn passkeys, MFA enrollment gate, database-backed sessions, session revocation and recovery-code controls (PR #23).
- Pinned Gitleaks 8.30.1 scanning all reachable Git history and removed high-entropy synthetic password literals from authentication tests (PR #27).
- Semgrep, Larastan/PHPStan, Gitleaks and OSV-Scanner security gates; weekly Composer and GitHub Actions Dependabot update configuration.
- Vendor-account read policy with a dedicated vendor-scoped permission, upgrade migration for existing system roles, and cross-vendor/stale-membership/role-revocation regression coverage (PR #38).
- Tag-triggered release workflow that builds an SPDX 2.3 SBOM and creates/verifies GitHub/Sigstore SLSA provenance and SBOM attestations before publishing a version-tagged cPanel artifact (PR #36).

### Security
- Bootstrap credentials are never persisted or flashed to browser input; successful setup requires removal of the environment secret and clearing cached configuration.
- MFA management and recovery-code retrieval require recent password confirmation; recovery codes are returned only after an explicit user action and are not embedded in rendered pages by default.
- Password resets and global session revocation execute in one database transaction; successful login upgrades supported weaker legacy password hashes to the configured stronger driver without downgrading stronger hashes.
- Production artifact verification rejects environment files, package-authentication files and private-key material.
- Pull-request CI artifacts are not cryptographically signed or published as releases. The tag-only signed release workflow has not yet been exercised with a real protected version tag; independent consumer verification remains outstanding.

### Not yet released
No production TLMS service or production software package is published. Resource-level ABAC, maker-checker and privileged step-up workflows, customer/vendor portals, product and licence lifecycle, payment processing, cryptographic signing, secure updates, customer SDK, Talyvane integration and live cPanel provider qualification remain in development.
