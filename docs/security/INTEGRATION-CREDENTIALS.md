# Integration API credential management

## Product requirement

A platform Super Admin must be able to configure supported external provider API keys and credentials from the TLMS administrator dashboard. Normal provider setup must not require editing source code, committing secrets, or manually changing application files.

Navigation target: **Admin Settings → Integrations → [Provider] → Credentials**.

## Provider catalogue

The integration registry should support adapter-defined fields and validation for:
- Fiat payment gateways.
- Crypto payment processors, only after legal/provider qualification.
- Email and transactional messaging providers.
- SMS providers.
- Object storage and package/CDN providers.
- FX, tax and other approved data providers.
- Future supported external services.

Each adapter declares required credential fields, secret/non-secret classification, environment mode (test/live), validation method, capabilities, rotation support and safe diagnostic messages. Do not accept arbitrary executable configuration or arbitrary outbound URLs from the dashboard.

## Credential lifecycle

1. Super Admin opens the provider settings page.
2. Selects sandbox/test or production mode where applicable.
3. Enters credentials into write-only secret fields.
4. TLMS validates format and encrypts the secret before storage.
5. The provider adapter performs a bounded test connection when requested.
6. Super Admin explicitly activates the new credential version.
7. Provider operations resolve the active version through the credential service.
8. Rotation creates a new version; the old active version remains usable until the new one is validated and activated, where provider semantics allow.
9. Disable/revoke is audited and immediately prevents new provider calls using that credential.
10. UI displays configured/not configured, provider mode, masked suffix, status, last validated, last used and non-sensitive error summary. It never displays the saved secret.

## Data model direction

Use a provider-neutral credential model with:
- id, scope_type, scope_id;
- provider_key, credential_name, environment;
- encrypted_payload, encryption_key_id, schema_version;
- status (draft, validated, active, disabled, rotation_pending, invalid);
- version, created_by, updated_by, activated_by;
- created_at, updated_at, last_validated_at, last_used_at;
- safe validation outcome and correlation ID.

Secret payloads must be authenticated ciphertext. Do not store API keys in generic application settings, plaintext JSON, cache keys, session state, job payloads or audit metadata. Avoid storing a secret fingerprint unless there is a documented operational need; a keyed fingerprint can be used for duplicate detection if its key is separately protected.

## Authorization

- Platform-global provider credentials: Super Admin only.
- Tenant-scoped credentials (only where explicitly supported): tenant owner/admin with a dedicated permission, isolated by tenant scope.
- Support agents, developers, read-only auditors and ordinary admins do not receive secret write permissions by default.
- Every request must enforce authorization server-side and tenant scope in the service/repository layer.
- Require MFA and recent authentication for high-impact credential mutations.

## Cryptographic storage

- Use authenticated encryption (Sodium secretbox or reviewed AEAD).
- Encryption key is external to the database, stored in protected deployment configuration outside the public document root.
- Include key ID and schema version to support rotation and migration.
- Use constant-time authentication checks supplied by the crypto library.
- Fail closed if the encryption key is missing or invalid.
- Never fall back to plaintext storage.
- Do not use the Laravel application key as an undocumented all-purpose substitute; define a dedicated credential-encryption key and rotation lifecycle.
- Provide an optional managed-secret-store adapter for environments that support KMS/secret managers.
- The database and encryption key require separate backup access controls.

## Logging and diagnostics

Never log request bodies containing credential fields, authorization headers, API tokens, private keys, webhook secrets or decrypted provider responses containing sensitive data. Redact at ingress and exception boundaries. Audit only metadata and outcome. Provider error messages must be normalized to prevent secret reflection.

## Security and reliability tests

- Role matrix tests for all credential endpoints and UI actions.
- Tenant isolation tests.
- Ciphertext-at-rest and tamper-detection tests.
- No-secret-in-response, log, audit, exception, cache, session or queue tests.
- Failed validation preserves the existing active credential.
- Rotation, disablement, key-version migration and restore tests.
- Provider timeout, rate limit, malformed response and outage tests.
- CSRF, replay, mass-assignment, IDOR and SSRF tests.
- Concurrent update and stale-version tests.

## Important boundary

API credentials for external providers are not TLMS licence-signing private keys. Root signing keys remain offline. Delegated signing keys use a separately controlled signing subsystem and must not be exposed as general API credential fields.
