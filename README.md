# TLMS — Tinlance License Management System

**Enterprise-grade software licensing, entitlement, activation, payment and secure update management.**

TLMS is being built by Tinlance Limited as a commercially licensable platform for software vendors. It is intended to support self-hosted deployments, shared-hosting installations, product-specific entitlements, customer and administrator portals, payment integrations, offline verification, and secure software distribution.

## Project status

**Development status:** Phase 4 identity and organization foundation. Laravel 13, a committed Composer lockfile, licensing-key/domain primitives, ULID-backed identity and membership schema, a scoped role/permission catalogue and deny-by-default membership authorization service are implemented. Login/MFA flows, one-time Super Admin bootstrap, resource-level ABAC, portals, licence lifecycle APIs, payments, SDKs, secure updates and production deployment remain unimplemented.

This repository is public by deliberate strategy. Public visibility does not mean the project is open source or grant general commercial, production, white-label or redistribution rights. Read [LICENSE](LICENSE), [Commercial Licensing](COMMERCIAL-LICENSING.md) and [Trademarks](TRADEMARKS.md).

No production-ready application is currently published. Do not use this repository as a production licensing service.

## Approved architectural direction

- Laravel modular monolith for the central control plane.
- PHP 8.5 production target, with compatibility testing across supported PHP 8.3–8.5 runtimes.
- MySQL 8.4 canonical production database; MariaDB compatibility only after a separate test suite.
- Annual prepaid cPanel/shared hosting as the initial central hosting target, subject to a real-provider proof of concept.
- One self-hosted TLMS deployment per vendor initially; preserve a path to hosted multi-tenancy without making it a launch dependency.
- Independent PHP customer SDK with no Laravel runtime dependency.
- Ed25519 signatures using PHP Sodium, offline root authority and delegated operational keys.
- Signed offline licence leases and bounded degraded operation.
- Platform-wide Super Admin authority through a centralized Control Center spanning all modules, with server-side authorization, MFA/step-up, audited actions and explicit cross-tenant controls.
- Super Admin-managed provider API credentials in Admin Settings, stored as authenticated ciphertext with write-only secret fields, scoped access, validation and rotation.
- Provider-adapter payment architecture; crypto activation gated on legal and provider qualification.
- TUF-compatible secure update metadata and signed release artifacts.
- Test-driven development, CI enforcement, security analysis and documented recovery.

These are approved planning defaults, not evidence that the capabilities have been implemented.

## Local development

Requirements: PHP 8.3, 8.4 or 8.5; Composer 2; PHP intl, PDO SQLite and the extensions required by Laravel 13.

~~~bash
composer install
composer validate --strict
composer audit --locked
composer test
composer test:framework
composer format:check
~~~

- composer test runs the dependency-free domain suite (currently 25 test methods).
- composer test:framework runs the Laravel PHPUnit feature suite.
- composer format:check enforces Laravel Pint formatting.
- CI exercises PHP 8.3, 8.4 and 8.5. The local SQLite test database is not a substitute for MySQL 8.4 integration testing.

## Hosting and security boundaries

The HTTP document root must point to public/, never the repository root. Keep .env, application source, vendor/, logs, backups and private keys outside the public webroot. Production must use APP_DEBUG=false, a unique generated APP_KEY, HTTPS, and SESSION_SECURE_COOKIE=true. The bootstrap defaults to file sessions/cache and synchronous queue processing for shared-hosting compatibility; durable database-backed outbox and cron processing are future implementation work.

## Roadmap

See [Implementation Status](IMPLEMENTATION-STATUS.md), [the engineering roadmap](docs/architecture/ROADMAP.md), [Laravel application foundation](docs/architecture/LARAVEL-APPLICATION-FOUNDATION.md), [identity schema](docs/architecture/IDENTITY-SCHEMA.md), [authorization matrix](docs/architecture/AUTHORIZATION-MATRIX.md), [domain model](docs/architecture/DOMAIN-MODEL.md), [API contracts](docs/architecture/API-CONTRACTS.md), [OpenAPI v1 draft](docs/api/openapi.yaml), [threat model](docs/security/THREAT-MODEL.md), [licence-key verification and pepper lifecycle](docs/security/LICENCE-KEY-VERIFICATION.md), [requirements traceability](docs/architecture/REQUIREMENTS-TRACEABILITY.md), [architecture decision register](docs/architecture/OPEN-DECISIONS.md), [lifecycle state machines](docs/architecture/STATE-MACHINES.md), [privacy and data governance](docs/security/PRIVACY-AND-DATA-GOVERNANCE.md), [shared-hosting operations](docs/operations/SHARED-HOSTING-OPERATIONS.md), [domain-name normalization](docs/security/DOMAIN-NAME-NORMALIZATION.md) and the architecture decision records in [docs/adr](docs/adr).

## Security

Report vulnerabilities privately. See [SECURITY.md](SECURITY.md). Never commit secrets, private signing keys, customer data or production configuration.

## Contributions

External code contributions are not yet being accepted while contributor rights are finalized. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Commercial enquiries

Contact Tinlance Limited through its official business channels. Commercial, white-label and redistribution rights require a separate written agreement.
