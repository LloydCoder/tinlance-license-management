<?php

declare(strict_types=1);

namespace Tlms\\Domain\\Licensing;

use InvalidArgumentException;
use stdClass;

final class Ed25519Envelope
{
    /**
     * @param array<string, mixed> $payload
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
                'sig' => self::base64UrlEncode($signature),
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @param array<string, string> $trustedPublicKeys Raw 32-byte Ed25519 public keys keyed by key ID.
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

        $verifiedKeyIds = [];

        foreach ($signatures as $entry) {
            if (
                ! is_array($entry)
                || ! is_string($entry['keyid'] ?? null)
                || ! is_string($entry['sig'] ?? null)
                || preg_match('/\\A[a-f0-9]{64}\\z/D', $entry['keyid']) !== 1
            ) {
                return false;
            }

            $keyId = $entry['keyid'];
            if (isset($verifiedKeyIds[$keyId])) {
                continue;
            }

            $publicKey = $trustedPublicKeys[$keyId] ?? null;
            if (! is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            if (! hash_equals($keyId, Ed25519KeyPair::keyIdForPublicKey($publicKey))) {
                return false;
            }

            $signature = self::base64UrlDecode($entry['sig']);
            if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            if (sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                $verifiedKeyIds[$keyId] = true;
                if (count($verifiedKeyIds) >= $threshold) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\\A[A-Za-z0-9_-]+\\z/D', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4),
            true,
        );

        if ($decoded === false || self::base64UrlEncode($decoded) !== $value) {
            return null;
        }

        return $decoded;
    }
}
