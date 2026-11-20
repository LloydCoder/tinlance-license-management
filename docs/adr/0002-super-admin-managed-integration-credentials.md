# ADR 0002: Super-admin managed integration credentials

- **Status:** Accepted project requirement
- **Date:** 2026-11-20
- **Decision owners:** Tinlance Limited

## Context

TLMS must be installable by customers who are not expected to edit source files or deployment environment variables whenever they configure a supported external provider. The platform operator needs a controlled dashboard workflow to configure provider API credentials.

## Decision

Provide a dedicated **Admin Settings → Integrations / API Credentials** interface. Only the platform Super Admin role may create, replace, activate, disable or rotate platform-global provider credentials. The interface will support provider-specific configuration for payment gateways, crypto payment processors, email delivery, SMS, storage/CDN, tax or FX data, and other explicitly supported integrations.

The design must distinguish:
- **Platform-global credentials**, managed by TLMS Super Admin and used for platform operations.
- **Tenant-owned credentials**, if a future integration explicitly supports customer-supplied provider accounts; these are separately scoped and managed by an authorized tenant owner/admin.
- **TLMS signing authority**, which is not an ordinary provider API credential and is not provisioned through the general integrations form. Root signing keys require a separate offline ceremony and operational-key lifecycle.

## Security requirements

1. Encrypt provider secrets before persistence using authenticated encryption (libsodium secretbox or an equivalently reviewed AEAD construction). Never store plaintext credentials.
2. Keep the encryption key outside the database and outside the web document root, supplied through protected deployment configuration. Support key identifiers and a documented encryption-key rotation procedure.
3. Never expose a saved secret again through the API or UI. After save, show only configured status, provider/account metadata, last four characters where safe, creation/update time and last validation result.
4. Do not return secrets in HTML, JSON, logs, exception traces, audit payloads, analytics, exports, backups in plaintext, or support diagnostics.
5. Require Super Admin authorization, MFA and recent re-authentication for reveal-free write/replace/disable actions. Enforce authorization server-side on every endpoint.
6. Audit actor, provider, credential record ID, action, timestamp, request correlation ID and outcome; never audit the secret value.
7. Provide provider-specific validation, safe test-connection, enable/disable, rotation, last-used/last-validated metadata and actionable non-sensitive errors.
8. Use separate credentials per provider and environment where supported. Never reuse one provider secret across unrelated services.
9. Support credential versioning and staged rotation where provider APIs permit overlap. Failed validation must not silently replace a working credential.
10. Apply rate limits, CSRF protection for browser actions, strict input validation and SSRF-safe provider connection behavior. Provider endpoints must be allow-listed or fixed by integration adapters; do not let a settings user turn the server into an arbitrary URL fetcher.
11. Ensure queue jobs and webhooks resolve the currently active credential version safely and do not serialize secret values into job payloads.
12. Backups containing encrypted credential records must be protected; the external encryption key must be backed up separately under restricted access. Restore tests must verify key/database pairing.

## Shared-hosting deployment profile

The initial shared-hosting profile uses an encrypted-database credential store with a dedicated encryption key in a protected environment file outside the public document root. The key must not be committed to source control or entered into the same database it encrypts. The design must disclose that compromise of the entire hosting account may expose both ciphertext and the runtime key; this is not equivalent to an external HSM or managed KMS.

An optional external secrets manager/KMS adapter may be added for deployments that support it, without making it mandatory for shared hosting.

## Acceptance criteria

- Non-Super-Admin actors cannot read, create, replace, enable, disable or delete platform-global credentials.
- Super Admin can configure and validate supported provider credentials from the dashboard without editing source code or application files.
- After saving, the raw value cannot be retrieved through any dashboard/API response.
- Database inspection shows authenticated ciphertext, not plaintext.
- Logs, audit events, exceptions and queued payloads contain no secret values.
- Invalid new credentials do not destroy the currently active credential.
- Rotation, disablement, restore and encryption-key rotation have automated tests and documented runbooks.
- Missing encryption-key configuration fails closed for credential writes and provider calls; the application must not silently store plaintext.
- Tenant-scoped credentials cannot be accessed across tenant boundaries.

## Consequences

- A secrets abstraction and encrypted credential repository are foundational platform services.
- Provider adapters cannot read arbitrary settings rows; they receive scoped credentials through the credential service.
- The admin dashboard is the normal operational configuration surface, while deployment-level encryption keys and root signing authority remain outside the dashboard.
- Enterprise deployments may replace the database-backed store with a managed secrets service.
