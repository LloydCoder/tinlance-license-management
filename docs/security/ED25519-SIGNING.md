# TLMS Ed25519 signing foundation

**Status:** Domain-level signing and verification primitives implemented. Production key custody, offline-root delegation, rotation/revocation policy, signing ceremonies and compromise recovery are not yet implemented or approved.

## Trust boundaries

- The offline root signing key must remain outside the TLMS runtime and outside customer distributions.
- Operational signing keys must be delegated by trusted root metadata and scoped to explicit roles. A runtime must not promote its own key into the trust store.
- Public verification keys and key identifiers may be distributed to verifiers. Private seeds and secret keys must never be committed, stored in the application database, included in a cPanel/customer artifact, returned by an API, or written to logs.
- The current `Ed25519KeyPair` is an in-memory domain primitive for controlled signing workflows. It rejects serialization, redacts debug output, supports explicit destruction and clears transient seed/keypair buffers after derivation. It is not a secret manager or an operational key-custody solution.

## Signature envelope v1

The signed envelope contains:

- `signed`: the canonical JSON object containing the claims or metadata.
- `signatures`: detached Ed25519 signatures, each containing a `keyid` and hex-encoded `sig`.

The signer signs only the canonical bytes of the `signed` object. The verifier resolves key IDs from an independently supplied trusted-public-key map, recomputes each key ID from the Ed25519 public-key descriptor, verifies detached signatures with libsodium, and rejects duplicate key IDs in the signature list, and counts only distinct trusted public keys toward the configured threshold.

The key identifier is SHA-256 over the canonical JSON encoding of the TUF-compatible public-key descriptor:

`{ "keytype": "ed25519", "scheme": "ed25519", "keyval": { "public": "<64-character-hex-public-key>" } }`

This makes the key identifier deterministic and compatible with the public-key descriptor shape used by TUF. It does not, by itself, establish trust: trust comes only from a separately verified root/delegation policy.

## Canonical JSON profile

TLMS signed payload v1 uses a deliberately constrained canonical JSON profile:

- Object keys are stable ASCII identifiers and sorted bytewise.
- Lists preserve their original order.
- String values must be valid UTF-8.
- Integer, boolean and null values are supported; integers are limited to the JavaScript-safe range ±(2^53−1) for cross-language numeric fidelity.
- Floating-point values and unsupported PHP values are rejected.
- Empty JSON objects are represented explicitly as `stdClass`; empty PHP arrays represent JSON lists.

The profile avoids numeric representation ambiguity and keeps key ordering interoperable. It is not a claim of full RFC 8785 support for arbitrary JSON values. Any future cross-language implementation must consume the published test vectors and reproduce the exact canonical bytes.

## Cryptographic implementation

- Algorithm: Ed25519 detached signatures.
- Runtime: PHP Sodium extension.
- Key seed: exactly 32 bytes.
- Public key: 32 bytes.
- Secret signing key: 64-byte libsodium Ed25519 secret-key representation derived from the seed.
- Signature: 64 bytes, encoded as 128 lowercase hexadecimal characters in the envelope.
- Key ID: lowercase SHA-256 hex digest of the canonical public-key descriptor.

The unit suite includes RFC 8032 test vector 1, canonicalization, tamper rejection, trusted-key matching, distinct-key threshold counting, malformed seed rejection and secret debug-output redaction.

## Not yet implemented

1. Offline root metadata and root-key ceremony.
2. Delegated operational signing roles and threshold approval.
3. Persisted public-key registry, activation/revocation states and key-rotation events.
4. Trusted root update and compromise-recovery workflow.
5. Production key-provider interface backed by a separately controlled secret facility.
6. Signed licence claim schema bound to the immutable plan-version snapshot.
7. Independent verifier package and cross-language golden fixtures.
8. TUF root/targets/snapshot/timestamp metadata and update-client verification (Phase 10).

Do not enable production licence signing until the trust hierarchy, custody, rotation, revocation and recovery decisions are approved and tested.
