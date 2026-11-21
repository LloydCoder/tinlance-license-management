# ADR 0005: Initial implementation technical baseline

> **Supersession note (2026-11-21):** The database compatibility candidate in this initial baseline is superseded by [ADR 0006](0006-mariadb-10-11-cpanel-compatibility.md). MySQL 8.4 remains the primary reference; MariaDB 10.11 is now the tested cPanel/shared-hosting compatibility engine. The earlier MariaDB 11.8 candidate is not a supported target.

- **Status:** Accepted implementation baseline; provider-dependent and legal decisions remain open
- **Date:** 2026-11-20
- **Decision owners:** Tinlance Limited

## 1. Runtime and framework

- Use Laravel 13.x modular monolith for the central control plane.
- PHP 8.3 is the minimum supported runtime because Laravel 13 requires PHP 8.3; test PHP 8.3, 8.4 and 8.5 in CI.
- PHP 8.5 is the preferred production runtime when available from the selected host.
- PHP intl is required for IDNA hostname normalization; fail closed if the extension is unavailable.
- Do not claim PHP 8.6 compatibility until Laravel 13 and all dependencies explicitly support it and CI verifies it.
- Keep the customer PHP SDK framework-independent and compatible with its separately declared PHP runtime matrix.

Laravel 13's official support matrix lists PHP 8.3–8.5 and a minimum of PHP 8.3. PHP's official support table shows PHP 8.3 security support through 31 December 2027, 8.4 through 31 December 2028 and 8.5 through 31 December 2029. Re-evaluate the matrix at each major release.

## 2. Database baseline

- Canonical database target: MySQL 8.4 LTS.
- MariaDB is a separately tested compatibility target, not an assumed drop-in equivalent. Start with MariaDB 11.8 LTS where a provider offers it; only publish a MariaDB version as supported after migration, transaction, JSON, collation, locking and concurrency suites pass.
- Do not require PostgreSQL for the shared-hosting profile.
- Use InnoDB transactions, foreign keys, unique constraints and explicit indexes.
- Store timestamps in UTC; use explicit timezone conversion at presentation/business-rule boundaries.
- Use ULID primary/public identifiers for new domain records. ULIDs are identifiers, not authorization secrets; every object access still requires policy checks.
- Avoid vendor-specific SQL where possible; isolate unavoidable differences behind repository/query boundaries and test them.

MySQL identifies LTS releases as the stable track for environments needing longer support and reduced behavioral change. MySQL 8.4 LTS is selected as the initial canonical baseline.

## 3. Identity and authentication

- Human identity uses Laravel's supported session authentication for browser portals, secure HttpOnly/Secure/SameSite cookies, CSRF protection and server-side authorization policies.
- Require MFA for all vendor administrators; WebAuthn/passkeys are preferred, with TOTP as a compatibility fallback and single-use recovery codes.
- Super Admin bootstrap uses a one-time setup flow, no default credentials, and immediate MFA enrollment. Recovery requires verified out-of-band control, step-up and immutable audit.
- Admin API tokens are opaque random values with a public token identifier, a secret component, explicit scopes, expiry and revocation. Persist only a keyed verifier/hash; show the token once.
- SDK installation credentials are separate from human admin credentials and limited to product/installation operations.
- Provider webhooks use provider-specific signature verification over raw request bytes.

## 4. Identifiers and secrets

- Use ULID for internal/public record identifiers; do not rely on unguessable IDs as access control.
- Licence activation keys: 256-bit CSPRNG secret encoded for safe copy/paste, with a non-secret product/version prefix. Display once at issuance. Persist only a keyed HMAC-SHA-256 verifier using a separate protected pepper; never store plaintext.
- API tokens: at least 256 bits of random secret material; persist only a keyed verifier/hash, support scope, expiry, rotation and immediate revocation.
- Provider credentials: authenticated encryption with dedicated external encryption key per ADR 0002.
- Licence signing and update signing keys: separate signing subsystem, Ed25519, offline root and delegated operational keys; never general dashboard credentials.

## 5. Domain and installation binding

- Canonicalize hostnames using IDNA to ASCII, lowercase, and remove one terminal dot.
- Reject schemes, paths, query strings, fragments, embedded credentials and ports in the domain binding input.
- Wildcard domains are disabled by default. Subdomain coverage must be an explicit versioned entitlement/policy; apex and subdomains are not silently conflated.
- Localhost/private development domains are accepted only for development/staging/NFR grants and must not satisfy production activation policy.
- Domain normalization and matching rules must be property/fuzz tested, including Unicode, punycode, trailing dots and malformed labels.

## 6. Offline lease policy

- Default signed offline lease validity: 72 hours.
- SDK should attempt refresh before expiry (nominally after 24 hours, with bounded jitter/backoff).
- No offline client may extend a lease by itself or accept an expired lease as valid.
- Enterprise deployments may configure a longer lease only through an explicit policy, with a hard maximum of 7 days unless a later risk-reviewed ADR changes it.
- Licence term grace and offline connectivity lease are distinct concepts.
- Central outage behavior, clock rollback handling and lease revocation delay must be visible to customers and tested.

These values are initial risk-based defaults, not a claim that revocation is instantaneous while offline.

## 7. Money and financial integrity

- Fiat values are integer minor units plus ISO 4217 currency code; crypto values are integer base units plus chain/network and token contract identity.
- FX rates are immutable snapshots with source, quote timestamp, precision and rounding rule.
- Ledger is append-only and balanced; corrections are compensating postings.
- Payment event ingestion, allocation and licence issuance are idempotent and transactional or use a transactional outbox.
- Provider redirect success is never authoritative payment evidence.
- Crypto payment processing remains disabled until legal/provider qualification.

## 8. Audit and operations

- Audit events are append-only at the application layer and hash-chained with sequence number, previous hash and canonical event digest.
- Periodically anchor audit chain digests to independently controlled off-site storage. A hash chain held only in the same writable database is not sufficient against a database administrator who can rewrite the full chain.
- Use a database-backed outbox and recoverable scheduled processing. No persistent queue worker or Redis is mandatory.
- Target cPanel cron cadence of every 5 minutes where the selected provider allows it; critical activation and webhook acceptance remain synchronous and durable.
- External uptime/cron/backup monitoring and independent encrypted backups are mandatory.
- RPO 15 minutes and RTO 4 hours remain targets until measured in a real restore exercise.

## 9. Secure update profile

- Follow the current TUF specification and its four required top-level roles: root, targets, snapshot and timestamp; use delegated targets where useful.
- Enforce signature thresholds, key IDs, metadata expiry, monotonically increasing versions, consistent snapshots, hashes/sizes and freeze/rollback protections.
- Keep root authority offline. Use separate role keys; timestamp is the short-lived online role only if the deployment can reliably refresh metadata.
- Publish immutable artifacts with digest, SBOM and build provenance.
- The exact root threshold and operational key custody ceremony must be finalized before production signing.

## 10. Human password hashing

- Use Argon2id as the default password hashing algorithm with minimum parameters of 19 MiB memory, 2 iterations and 1 lane, meeting the OWASP Password Storage Cheat Sheet baseline.
- Enforce configuration floors so environment settings cannot silently reduce the selected minimum. Rehash on login remains enabled.
- An explicit bcrypt fallback is available only for approved compatibility cases, with work factor at least 12 and a hard 72-byte input limit; bcrypt is not the default for new installations.
- The selected host must expose Argon2id through PHP password hashing and pass realistic memory/concurrency benchmarks before qualification.
- Store password hashes only through Laravel's hashing service and hashed model cast. Never store plaintext or reversible password encryption.

## 11. Consequences and release gates

- This ADR is the baseline for architecture and CI, not proof of provider compatibility.
- The selected cPanel provider must support PHP 8.3+ and a qualified database/extension set; if it does not, choose another provider rather than silently lowering the runtime.
- MariaDB compatibility is opt-in per tested version.
- Offline lease duration, domain policy and key formats require unit/property tests and SDK contract vectors.
- Production signing, payments, crypto, privacy retention and RPO/RTO require their separate approval and evidence gates.
