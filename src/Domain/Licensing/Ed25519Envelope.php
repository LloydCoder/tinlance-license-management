<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;
use stdClass;

final class Ed25519Envelope
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{signed: stdClass, signatures: list<array{keyid: string, sig: string}>}
     */
    public static function sign(array $payload, Ed25519KeyPair $keyPair): array
    {
        $signed = CanonicalJson::normalizeObject($payload);
        $signature = $keyPair->sign(CanonicalJson::encodeObject($signed));

        return [
            'signed' => $signed,
            'signatures' => [[
                'keyid' => $keyPair->keyId(),
                'sig' => bin2hex($signature),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, string>  $trustedPublicKeys  Raw 32-byte Ed25519 public keys keyed by key ID.
     */
    public static function verify(array $envelope, array $trustedPublicKeys, int $threshold = 1): bool
    {
        $signed = $envelope['signed'] ?? null;
        $signatures = $envelope['signatures'] ?? null;

        if (
            $threshold < 1
            || ! ($signed instanceof stdClass)
            || ! is_array($signatures)
            || ! array_is_list($signatures)
        ) {
            return false;
        }

        try {
            $message = CanonicalJson::encodeObject($signed);
        } catch (InvalidArgumentException) {
            return false;
        }

        $seenKeyIds = [];
        $verifiedKeyIds = [];

        foreach ($signatures as $entry) {
            if (
                ! is_array($entry)
                || ! is_string($entry['keyid'] ?? null)
                || ! is_string($entry['sig'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['keyid']) !== 1
                || preg_match('/\A[a-f0-9]{128}\z/D', $entry['sig']) !== 1
            ) {
                return false;
            }

            $keyId = $entry['keyid'];
            if (isset($seenKeyIds[$keyId])) {
                return false;
            }
            $seenKeyIds[$keyId] = true;

            $publicKey = $trustedPublicKeys[$keyId] ?? null;
            if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            if (! hash_equals($keyId, Ed25519KeyPair::keyIdForPublicKey($publicKey))) {
                return false;
            }

            $signature = hex2bin($entry['sig']);
            if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            if (sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                $verifiedKeyIds[$keyId] = true;
            }
        }

        return count($verifiedKeyIds) >= $threshold;
    }
}
