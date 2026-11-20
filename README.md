# TLMS — Tinlance License Management System

**Enterprise-grade software licensing, entitlement, activation, payment and secure update management.**

TLMS is being built by Tinlance Limited as a commercially licensable platform for software vendors. It is intended to support self-hosted deployments, shared-hosting installations, product-specific entitlements, customer and administrator portals, payment integrations, offline verification, and secure software distribution.

## Project status

**Development status:** Pre-implementation / Phase 0 governance baseline.

This repository is public by deliberate strategy. Public visibility does not mean the project is open source or grant general commercial, production, white-label or redistribution rights. Read [LICENSE](LICENSE), [Commercial Licensing](COMMERCIAL-LICENSING.md) and [Trademarks](TRADEMARKS.md).

No production-ready application is currently published. Do not use this repository as a production licensing service.

## Approved architectural direction

- Laravel modular monolith for the central control plane.
- PHP 8.5 production target, with compatibility testing across supported PHP 8.3–8.5 runtimes.
- MySQL/MariaDB deployment profile, qualified against actual shared-hosting providers.
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

## Roadmap

See [IMPLEMENTATION-STATUS.md](IMPLEMENTATION-STATUS.md), [the engineering roadmap](docs/architecture/ROADMAP.md), [authorization matrix](docs/architecture/AUTHORIZATION-MATRIX.md), [domain model](docs/architecture/DOMAIN-MODEL.md), [API contracts](docs/architecture/API-CONTRACTS.md), [OpenAPI v1 draft](docs/api/openapi.yaml), [threat model](docs/security/THREAT-MODEL.md), [requirements traceability](docs/architecture/REQUIREMENTS-TRACEABILITY.md) and the architecture decision records in [docs/adr](docs/adr).

## Security

Report vulnerabilities privately. See [SECURITY.md](SECURITY.md). Never commit secrets, private signing keys, customer data or production configuration.

## Contributions

External code contributions are not yet being accepted while contributor rights are finalized. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Commercial enquiries

Contact Tinlance Limited through its official business channels. Commercial, white-label and redistribution rights require a separate written agreement.
