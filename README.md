# TLMS — Tinlance License Management System

**Enterprise-grade software licensing, entitlement, activation, payment and secure update management.**

TLMS is being built by Tinlance Limited as a commercially licensable platform for software vendors. It is intended to support self-hosted deployments, shared-hosting installations, product-specific entitlements, customer and administrator portals, payment integrations, offline verification, and secure software distribution.

## Project status

**Development status:** Phase 4 identity and authorization foundation. Laravel 13, licensing-key/domain primitives, ULID-backed identity schema, scoped permissions, deny-by-default membership authorization, transactional role assignment/revocation and immutable role-event evidence are implemented. Customer-organization, vendor-account and membership read policies enforce tenant-scoped access; customer organization authorization reloads persisted organization/vendor state and denies suspended organizations or stale tenant bindings. Fortify authentication includes email verification, password recovery, TOTP, WebAuthn passkeys, MFA enrollment gating, encrypted database sessions and session revocation. The self-service MFA enrollment page is available to all authenticated, verified users, not only Super Admins. Recent password confirmation protects Super Admin session management; protected requests invalidate sessions for suspended users. Self-only membership reads are allowed without granting collection listing or membership mutations. Fresh five-minute TOTP/passkey step-up protects membership role mutations and tenant-admin membership revocation. Vendor/customer membership revocation is transactional, denies self-revocation, preserves role history, records immutable lifecycle events and supports idempotent retries. Customer membership revocation also locks and revalidates its persisted parent vendor row to serialize against concurrent vendor suspension (PR #58). Maker-checker approval protects high-risk vendor role grants with distinct maker/checker, beneficiary conflict-of-interest protection, idempotent payload-bound requests, expiry and atomic decision/role evidence. These remain narrow controls, not complete resource-level ABAC or a general approval engine. Vendor/customer invitation APIs now support hashed single-use links, HTTPS-only delivery, expiry, resend, account setup, verified existing-account acceptance and revocation invalidation; account setup is bound to the invitee identity. PRs #66–#68 add the tenant-scoped pending-invitation query and management workspace for vendor/customer managers, including create/resend/revoke controls, direct-role allowlists, delivery/expiry/creation metadata, password confirmation, fresh TOTP/passkey step-up, POST-only CSRF-protected forms and same-origin JSON interactions. The workspace does not select or render invitation token material. PR #70 adds a tenant-scoped vendor_admin role with only vendor.account.read, admins.manage and audit.read; the first active manager is bootstrapped by a Super Admin, and subsequent grants require maker-checker approval. PRs #71–#72 establish the supplied TLMS Brand Kit v1.0 as the design authority and apply its unchanged horizontal lockups, app tile, SVG favicon and canonical palette to the authentication, tenant-workspace and initial setup shells. Descriptor/stacked lockups, full PWA/OS icons, seals, email/invoice/social assets and visual regression remain outstanding. PRs #74–#75 add audited vendor/customer membership reinstatement through a fresh invitation and expose revoked-member review/reinstatement forms in the tenant workspace; prior roles are cleared, previous tokens invalidated and access resumes only after acceptance. The cPanel release builder compiles passkey and invitation bundles with pinned Node.js 22.16.0 and removes Node/npm tooling from the production package; customer hosting does not require Node.js. PR #77 adds platform-bootstrap Super Admin read access to active vendor accounts and customer organizations across vendor tenants, requiring the original bootstrap identity and retained active primary-vendor Super Admin membership; each cross-tenant decision is recorded in application-level access audit events with Eloquent instance update/delete guards. Complete resource-level ABAC, non-membership mutation policies, full portals, product/licence lifecycle, payment workflows, generalized privileged step-up/approval, SDKs, secure updates and live cPanel provider qualification remain outstanding. The tag-triggered release workflow builds an SPDX SBOM and GitHub/Sigstore provenance attestations, but a real protected version-tag release and independent consumer verification have not yet been performed. CI verifies a production-only cPanel ZIP; no production deployment or production-ready service is claimed.

This repository is public by deliberate strategy. Public visibility does not mean the project is open source or grant general commercial, production, white-label or redistribution rights. Read [LICENSE](LICENSE), [Commercial Licensing](COMMERCIAL-LICENSING.md) and [Trademarks](TRADEMARKS.md).

No production-ready application is currently published. Do not use this repository as a production licensing service.

## Approved architectural direction

- Laravel modular monolith for the central control plane.
- PHP 8.5 production target, with compatibility testing across supported PHP 8.3–8.5 runtimes.
- MySQL 8.4 reference database and MariaDB 10.11 shared-hosting compatibility database, both exercised by integration CI; other MySQL/MariaDB versions require separate qualification.
- Annual prepaid cPanel/shared hosting as the initial central hosting target, subject to a real-provider proof of concept.
- One self-hosted TLMS deployment per vendor initially; preserve a path to hosted multi-tenancy without making it a launch dependency.
- Independent PHP customer SDK with no Laravel runtime dependency.
- Ed25519 signatures using PHP Sodium, offline root authority and delegated operational keys.
- Argon2id password hashing by default (19 MiB memory, 2 iterations, 1 lane), with a bounded explicit bcrypt fallback only for approved compatibility cases.
- Signed offline licence leases and bounded degraded operation.
- Platform-wide Super Admin authority through a centralized Control Center spanning all modules, with server-side authorization, MFA/step-up, audited actions and explicit cross-tenant controls.
- Super Admin-managed provider API credentials in Admin Settings, stored as authenticated ciphertext with write-only secret fields, scoped access, validation and rotation.
- Provider-adapter payment architecture; crypto activation gated on legal and provider qualification.
- TUF-compatible secure update metadata and signed release artifacts.
- Test-driven development, CI enforcement, security analysis and documented recovery.

These are approved planning defaults, not evidence that the capabilities have been implemented.

## Local development

Requirements: PHP 8.3, 8.4 or 8.5; Composer 2; PHP intl, PDO SQLite and the extensions required by Laravel 13, including Argon2id password hashing support. Node.js 22.16.0 or newer and npm are required only when building browser assets or a cPanel release package.

~~~bash
composer install
npm ci
npm run build
composer validate --strict
composer audit --locked
composer test
composer test:framework
composer format:check
composer analyse
~~~

- `composer test` runs the dependency-free domain suite (25 test methods).
- `composer test:framework` runs the Laravel PHPUnit feature suite; database integration runs separately on MySQL 8.4 and MariaDB 10.11.
- `composer format:check` enforces Laravel Pint formatting.
- `composer analyse` runs Larastan/PHPStan level 8 across app, domain, database, routes, bootstrap, config and tests; no baseline or ignored-error file is used.
- CI exercises PHP 8.3, 8.4 and 8.5, plus the feature suite against MySQL 8.4.13 and MariaDB 10.11. Local SQLite is a fast secondary test driver, not a substitute for either production database integration.

## Hosting and security boundaries

The HTTP document root must point to `public/`, never the repository root. Keep `.env`, application source, `vendor/`, logs, backups and private keys outside the public webroot. Production must use `APP_DEBUG=false`, a unique generated `APP_KEY`, HTTPS, and Secure session cookies (enabled by default). Password hashing defaults to Argon2id; a hosting provider must support it and pass a resource/concurrency benchmark. The bootstrap uses encrypted file sessions, file cache and synchronous queue processing for shared-hosting compatibility; durable database-backed outbox and cron processing are future implementation work. Node.js/npm are used only on the release build machine to compile static browser assets; the production cPanel package excludes Node build tooling and does not require Node.js on the customer's host.

## Roadmap

See [Architecture overview](ARCHITECTURE.md), [membership invitation lifecycle](docs/architecture/INVITATION-LIFECYCLE.md), [database design](DATABASE-DESIGN.md), [API specification](API-SPECIFICATION.md), [project roadmap](PROJECT-ROADMAP.md), [Changelog](CHANGELOG.md), [Implementation Status](IMPLEMENTATION-STATUS.md), [the engineering roadmap](docs/architecture/ROADMAP.md), [Laravel application foundation](docs/architecture/LARAVEL-APPLICATION-FOUNDATION.md), [identity schema](docs/architecture/IDENTITY-SCHEMA.md), [authorization matrix](docs/architecture/AUTHORIZATION-MATRIX.md), [domain model](docs/architecture/DOMAIN-MODEL.md), [API contracts](docs/architecture/API-CONTRACTS.md), [OpenAPI v1 draft](docs/api/openapi.yaml), [threat model](docs/security/THREAT-MODEL.md), [security analysis controls](docs/security/SECURITY-ANALYSIS.md), [licence-key verification and pepper lifecycle](docs/security/LICENCE-KEY-VERIFICATION.md), [requirements traceability](docs/architecture/REQUIREMENTS-TRACEABILITY.md), [architecture decision register](docs/architecture/OPEN-DECISIONS.md), [lifecycle state machines](docs/architecture/STATE-MACHINES.md), [privacy and data governance](docs/security/PRIVACY-AND-DATA-GOVERNANCE.md), [shared-hosting operations](docs/operations/SHARED-HOSTING-OPERATIONS.md), [domain-name normalization](docs/security/DOMAIN-NAME-NORMALIZATION.md) and the architecture decision records in [docs/adr](docs/adr).

## Security

Report vulnerabilities privately. See [SECURITY.md](SECURITY.md). Never commit secrets, private signing keys, customer data or production configuration.

## Contributions

External code contributions are not yet being accepted while contributor rights are finalized. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Commercial enquiries

Contact Tinlance Limited through its official business channels. Commercial, white-label and redistribution rights require a separate written agreement.
