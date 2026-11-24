# TLMS Ed25519 signing foundation

**Status:** Domain-level Ed25519 signing, canonical envelopes, public-key trust metadata, role thresholds and sequential root-rotation verification are implemented on the current Phase 7 branch. Production key custody, offline-root ceremonies, persisted trust distribution and compromise recovery are not yet implemented or approved.

## Trust boundaries

- The offline root signing key must remain outside the TLMS runtime and outside customer distributions.
- Operational signing keys must be delegated by trusted root metadata and scoped to explicit roles. A runtime must not promote its own key into the trust store.
- Public verification keys and key identifiers may be distributed to verifiers. Private seeds and secret keys must never be committed, stored in the application database, included in a cPanel/customer artifact, returned by an API, or written to logs.
- The current `Ed25519KeyPair` is an in-memory domain primitive for controlled signing workflows. It rejects serialization, redacts debug output, supports explicit destruction and clears transient seed/keypair buffers after derivation. It is not a secret manager or an operational key-custody solution.

## Signature envelope v1

The signed envelope contains:

- `signed`: the canonical JSON object containing the claims or metadata.
- `signatures`: detached Ed25519 signatures, each containing a `keyid` and hex-encoded `sig`.

The signer signs only the canonical bytes of the `signed` object. The verifier resolves key IDs from an independently supplied trusted-public-key map, recomputes each key ID from the Ed25519 public-key descriptor, verifies detached signatures with libsodium, rejects duplicate key IDs, and counts only trusted public keys toward the configured threshold.

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

## Signing trust metadata v1

`SigningTrustMetadata` validates a TLMS root-trust object with `_type`, `schema_version`, monotonic `version`, canonical UTC `expires`, Ed25519 `keys`, and `roles`. The required roles are `root` and `license`; each role names distinct known key IDs and a threshold no greater than its key count. Every public-key descriptor is checked against its TUF-compatible key ID before it can enter the trust set.

`SigningTrustMetadataVerifier` accepts a root rotation only when the candidate version is exactly the previous version plus one and the candidate envelope satisfies both the previously trusted root threshold and the candidate root's self-signature threshold. Intermediate root expiry is deferred while walking a sequential rotation chain. `verifyRootRotationChain` holds one fixed update-start time and checks expiry only on the final root, matching the TUF root-update workflow; the final trust metadata must be unexpired before it can verify operational role envelopes. This follows the relevant TUF root-update safety rules without claiming that TLMS implements the complete TUF metadata/client workflow.

Trust bootstrap must be supplied out-of-band. The runtime does not generate, store or trust its own root key. Removing an operational key from a newly accepted role policy revokes that key for subsequent role verification.

## Not yet implemented

1. Offline root-key ceremony, physical custody and recovery procedure.
2. Persisted public-key registry, root metadata distribution and append-only key-rotation audit events.
3. Production key-provider interface backed by a separately controlled secret facility.
4. Signed licence claim schema bound to the immutable plan-version/entitlement snapshot.
5. Independent verifier package and cross-language golden fixtures beyond the RFC 8032 and canonicalization vectors.
6. Full TUF root/targets/snapshot/timestamp metadata and update-client verification (Phase 10).
