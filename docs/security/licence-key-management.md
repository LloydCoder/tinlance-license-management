# TLMS licence key and issuance-response key management

## Key separation

TLMS uses two independent key rings. Never reuse key material between them.

- **TLMS_LICENSE_KEY_PEPPERS_JSON** stores the pepper ring used to create and verify licence-key HMACs. Each key must contain at least 32 random bytes. The immutable key ID is stored with each licence.
- **TLMS_LICENSE_RESPONSE_ENCRYPTION_KEYS_JSON** stores 32-byte XChaCha20-Poly1305 keys used only to encrypt a pending one-time issuance response. These keys are independent of Laravel APP_KEY.

## Generate key material

Generate each value on a trusted administrator workstation. Do not place generated values in source control, tickets, chat, CI output or application logs.

```sh
php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "="), PHP_EOL;'
```

Use separate generated values for each ring. Configure JSON maps from immutable key IDs to base64url values:

```dotenv
TLMS_LICENSE_KEY_PEPPERS_JSON={"pepper-2026-01":"<BASE64URL_PEPPER>"}
TLMS_LICENSE_KEY_ACTIVE_PEPPER_ID=pepper-2026-01
TLMS_LICENSE_RESPONSE_ENCRYPTION_KEYS_JSON={"response-2026-01":"<BASE64URL_RESPONSE_KEY>"}
TLMS_LICENSE_RESPONSE_ACTIVE_KEY_ID=response-2026-01
TLMS_LICENSE_ISSUANCE_SECRET_TTL_SECONDS=900
```

The sample values above are placeholders, not usable keys. Configure them through the hosting provider's protected environment/secrets facility. Do not commit real values.

## Rotation

1. Generate a new independent key and assign a new immutable key ID.
2. Add the new key to the existing JSON map while retaining every old key.
3. Change only the corresponding active key ID.
4. Verify issuance, verification and one-time secret delivery in staging.
5. Retain old pepper keys for as long as any licence references them. Retain old response-encryption keys until all pending ciphertext encrypted with them has expired and been purged under the retention policy.
6. Record the rotation approval and key ID change in the operator's change-management system. Never record key material.

Removing an old pepper makes licences using that pepper unverifiable. Removing an old response key makes still-pending encrypted issuance secrets unrecoverable.

## One-time secret delivery

The issuance endpoint stores only the HMAC verifier and pepper key ID on the licence. The plaintext key is encrypted in the issuance-request record using XChaCha20-Poly1305 with vendor/request/license context as authenticated associated data. The API response to issuance contains no plaintext key.

The original issuing actor must retrieve the secret through the dedicated endpoint within the configured 60–86400 second TTL. Retrieval atomically changes the request state to delivered, clears the ciphertext and appends a secret_delivered event. Subsequent retrieval attempts return 410 Gone. The secret response uses Cache-Control: no-store, Pragma: no-cache and Referrer-Policy: no-referrer.

A network failure after the server commits delivery cannot make the key retrievable again. Revoke and reissue if the one-time response is irrecoverably lost. This is an intentional confidentiality trade-off.

## Operational checks

- Keep system clocks synchronized; issuance and response TTLs use server UTC time.
- Restrict environment/secrets access to authorized operators and audit changes.
- Back up encrypted database data and key material separately with independent access controls.
- Test key restoration and rotation procedures before production changes.
- Never log request bodies, response bodies, licence keys, peppers or response-encryption keys.
