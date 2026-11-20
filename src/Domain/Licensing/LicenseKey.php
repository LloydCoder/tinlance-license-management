<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;

final class LicenseKey
{
    private const PREFIX = 'tlms_';
    private const ENTROPY_BYTES = 32;
    private const MIN_PEPPER_BYTES = 32;

    public static function issue(string $pepper): IssuedLicenseKey
    {
        self::assertPepper($pepper);

        $encodedSecret = rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
        $plaintext = self::PREFIX . $encodedSecret;
        $verifier = hash_hmac('sha256', $plaintext, $pepper);

        return IssuedLicenseKey::fromParts($plaintext, $verifier);
    }

    public static function verify(string $plaintext, string $verifier, string $pepper): bool
    {
        self::assertPepper($pepper);

        if (!preg_match('/\\Atlms_[A-Za-z0-9_-]{43}\\z/D', $plaintext)) {
            return false;
        }

        $encodedSecret = substr($plaintext, strlen(self::PREFIX));
        $decodedSecret = base64_decode(strtr($encodedSecret, '-_', '+/') . '=', true);

        if ($decodedSecret === false || strlen($decodedSecret) !== self::ENTROPY_BYTES) {
            return false;
        }

        if (!preg_match('/\\A[a-f0-9]{64}\\z/D', $verifier)) {
            return false;
        }

        $expectedVerifier = hash_hmac('sha256', $plaintext, $pepper);

        return hash_equals($expectedVerifier, $verifier);
    }

    private static function assertPepper(string $pepper): void
    {
        if (strlen($pepper) < self::MIN_PEPPER_BYTES) {
            throw new InvalidArgumentException('The licence-key pepper must contain at least 32 bytes.');
        }
    }
}
