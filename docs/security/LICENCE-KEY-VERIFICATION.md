# Licence-key verification and pepper lifecycle

**Status:** Initial framework-independent domain primitive implemented; persistence, pepper custody and operational rotation are not yet implemented.

## Credential format

- Prefix: `tlms_`.
- Secret: 32 bytes from PHP `random_bytes()`, Base64URL encoded without padding (43 characters).
- Entropy: 256 bits. The resulting plaintext key is displayed only at issuance and must never be stored in the database or written to logs.
- Stored verifier: lowercase hexadecimal HMAC-SHA-256 output (64 characters).
- HMAC input: pepper key identifier, a NUL-byte domain separator, and the complete canonical plaintext key.
- HMAC key: a dedicated random pepper of at least 32 bytes, separate from Laravel `APP_KEY` and stored outside the database and webroot.
- Comparison: `hash_equals()` for constant-time verifier comparison.

The key identifier is returned alongside the verifier and must be persisted as non-secret metadata. It is included in the HMAC input to prevent verifier ambiguity across pepper versions.

## Pepper rotation

A one-way HMAC verifier cannot be re-keyed offline because the original plaintext licence key is intentionally not retained. Therefore:

1. Provision a new random pepper outside the database and assign a new immutable key ID.
2. Use the new pepper ID for all newly issued keys.
3. Retain old peppers in a restricted verification key ring while any records still reference them.
4. On a successful presentation of an old-version plaintext key, verify using the record's pepper key ID and old pepper; within the same transaction, replace its verifier with a verifier generated using the current pepper and update the key ID.
5. Monitor remaining old-version records. Retire an old pepper only after every dependent verifier has been migrated or the corresponding licence has been expired/revoked under an approved migration policy.
6. If an old pepper is lost before migration completes, those stored verifiers cannot be validated. Recovery must follow a controlled reissue process; never bypass verification or silently accept a key.

Pepper provisioning, encryption-at-rest, access controls, dual-control approval, backup and disaster recovery remain to be implemented. Do not place production peppers in source control, ordinary application settings, logs, CI variables exposed to pull requests, or the database.

## Operational invariants

- Issuance returns plaintext exactly once; persistence stores verifier + pepper key ID only.
- API responses, audit events, logs, analytics, exceptions, exports and backups must not contain plaintext licence keys or pepper material.
- Pepper lookup is by the stored key ID, never by trying every configured secret indiscriminately.
- A missing key ID or unavailable pepper is a fail-closed verification error, not a successful or bypassed validation.
- Verification and opportunistic re-verification must be atomic with the licence record update.
- Rate-limit verification endpoints and use generic failure responses to reduce enumeration and timing oracles.
- A licence key is an authentication credential, not an authorization decision. Product, vendor, customer, installation, state and entitlement checks remain mandatory.

## Current test evidence

The domain test suite covers entropy/format, successful verification, tampering, wrong pepper, weak pepper, malformed key, pepper-key-ID domain separation, invalid key ID and distinct issuance. The suite is executed in CI on PHP 8.3, 8.4 and 8.5. This primitive is not yet wired into persistence, activation endpoints or SDKs.


## Implemented pepper-ring primitive

The framework-independent domain layer now includes:

- `LicenseKeyPepperRing`: validates a configured map of immutable pepper IDs to pepper material and requires the active pepper to exist.
- `verifyAndUpgrade()`: selects exactly the pepper named by the persisted record, verifies the presented key, and—only after successful verification—returns a replacement verifier under the active pepper when the stored pepper is old.
- `LicenseKeyVerificationResult`: reports validity, the effective pepper ID and an optional replacement verifier.

The ring does not write to storage. The future application service must persist the replacement verifier and active pepper ID atomically with the successful activation/verification transaction, using optimistic locking to prevent lost updates. If persistence fails, the old verifier remains authoritative and the client request must not be treated as committed. Unknown/retired pepper IDs fail closed; the ring never tries all peppers.

The primitive is not a secret manager. Production pepper retrieval, process memory exposure, deployment configuration, backup, access auditing, rotation approvals and incident response remain application/operations responsibilities.
