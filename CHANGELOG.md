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
- Semgrep, Larastan/PHPStan, Gitleaks and OSV-Scanner security gates; weekly Composer and GitHub Actions Dependabot update configuration.

### Security
- Bootstrap credentials are never persisted or flashed to browser input; successful setup requires removal of the environment secret and clearing cached configuration.
- Production artifact verification rejects environment files, package-authentication files and private-key material.
- Production artifact publication remains manual; the package is not cryptographically signed.

### Not yet released
No production TLMS service or production software package is published. Authentication/MFA, product and licence lifecycle, payment processing, secure updates, customer SDK, Talyvane integration and live cPanel provider qualification remain in development.
