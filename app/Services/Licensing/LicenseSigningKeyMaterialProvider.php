<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Exceptions\LicenseKeyConfigurationException;
use InvalidArgumentException;
use JsonException;
use Tlms\Domain\Licensing\Ed25519KeyPair;
use Tlms\Domain\Licensing\SigningTrustMetadata;

final class LicenseSigningKeyMaterialProvider
{
    private const MAX_SIGNING_KEYS = 32;

    public function activeLicenseKeyPair(SigningTrustMetadata $trust): Ed25519KeyPair
    {
        $encodedRing = config('tlms.licensing.signing_private_seeds_json');
        $activeKeyId = config('tlms.licensing.active_signing_key_id');

        if (
            ! is_string($encodedRing)
            || trim($encodedRing) === ''
            || ! is_string($activeKeyId)
            || preg_match('/\A[a-f0-9]{64}\z/D', $activeKeyId) !== 1
        ) {
            throw new LicenseKeyConfigurationException('The active licence signing key configuration is missing or invalid.');
        }

        try {
            $ring = json_decode($encodedRing, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LicenseKeyConfigurationException('The licence signing key ring is not valid JSON.', 0, $exception);
        }

        if (! is_array($ring) || $ring === [] || count($ring) > self::MAX_SIGNING_KEYS) {
            throw new LicenseKeyConfigurationException('The licence signing key ring must contain between one and thirty-two keys.');
        }

        $activeKeyPair = null;

        foreach ($ring as $keyId => $encodedSeed) {
            if (
                ! is_string($keyId)
                || preg_match('/\A[a-f0-9]{64}\z/D', $keyId) !== 1
                || ! is_string($encodedSeed)
            ) {
                $activeKeyPair?->destroy();
                throw new LicenseKeyConfigurationException('The licence signing key ring contains an invalid entry.');
            }

            $seed = $this->decodeSeed($encodedSeed);

            try {
                $keyPair = Ed25519KeyPair::fromSeed($seed);
            } catch (InvalidArgumentException $exception) {
                $activeKeyPair?->destroy();
                throw new LicenseKeyConfigurationException('A licence signing seed could not be loaded.', 0, $exception);
            } finally {
                sodium_memzero($seed);
            }

            if (! hash_equals($keyId, $keyPair->keyId())) {
                $keyPair->destroy();
                $activeKeyPair?->destroy();
                throw new LicenseKeyConfigurationException('A licence signing seed does not match its configured key ID.');
            }

            if ($keyId === $activeKeyId) {
                $activeKeyPair?->destroy();
                $activeKeyPair = $keyPair;
            } else {
                $keyPair->destroy();
            }
        }

        if (! ($activeKeyPair instanceof Ed25519KeyPair)) {
            throw new LicenseKeyConfigurationException('The configured active licence signing key is not present in the key ring.');
        }

        try {
            $trustedPublicKeys = $trust->publicKeysForRole('license');
        } catch (InvalidArgumentException $exception) {
            $activeKeyPair->destroy();
            throw new LicenseKeyConfigurationException('The trusted licence signing role is not configured.', 0, $exception);
        }

        $trustedPublicKey = $trustedPublicKeys[$activeKeyId] ?? null;
        if (! is_string($trustedPublicKey) || ! hash_equals($trustedPublicKey, $activeKeyPair->publicKey())) {
            $activeKeyPair->destroy();
            throw new LicenseKeyConfigurationException('The active licence signing key is not authorized by current trust metadata.');
        }

        return $activeKeyPair;
    }

    private function decodeSeed(string $encodedSeed): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $encodedSeed) !== 1) {
            throw new LicenseKeyConfigurationException('Licence signing seeds must be unpadded base64url-encoded 32-byte values.');
        }

        $seed = base64_decode(strtr($encodedSeed, '-_', '+/').'=', true);
        if (
            $seed === false
            || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES
            || $this->encodeSeed($seed) !== $encodedSeed
        ) {
            if ($seed !== false) {
                sodium_memzero($seed);
            }

            throw new LicenseKeyConfigurationException('A licence signing seed has an invalid encoding or length.');
        }

        return $seed;
    }

    private function encodeSeed(string $seed): string
    {
        return rtrim(strtr(base64_encode($seed), '+/', '-_'), '=');
    }
}
