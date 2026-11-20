<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;

final class LicenseKey
{
    private const PREFIX = 'tlms_';
    private const ENTROPY_BYTES = 32;
    private const MIN_PEPPER_BYTES = 32;

    public static function issue(string $pepper, string $pepperKeyId): IssuedLicenseKey
    {
        self::assertPepper($pepper);
        self::assertPepperKeyId($pepperKeyId);

        $encodedSecret = rtrim(strtr(base64_encode(random_bytes(self::ENTROPY_BYTES)), '+/', '-_'), '=');
        $plaintext = self::PREFIX . $encodedSecret;
        $verifier = self::createVerifier($plaintext, $pepper, $pepperKeyId);

        return IssuedLicenseKey::fromParts($plaintext, $verifier, $pepperKeyId);
    }

    public static function createVerifierForExistingKey(
        string $plaintext,
        string $pepper,
        string $pepperKeyId,
    ): string {
        self::assertPepper($pepper);
        self::assertPepperKeyId($pepperKeyId);

        if (!self::isCanonicalKey($plaintext)) {
            throw new InvalidArgumentException('The licence key has an invalid format.');
        }

        return self::createVerifier($plaintext, $pepper, $pepperKeyId);
    }

    public static function validatePepperConfiguration(string $pepper, string $pepperKeyId): void
    {
        self::assertPepper($pepper);
        self::assertPepperKeyId($pepperKeyId);
    }

    public static function verify(
        string $plaintext,
        string $verifier,
        string $pepper,
        string $pepperKeyId,
    ): bool {
        self::assertPepper($pepper);
        self::assertPepperKeyId($pepperKeyId);

        if (!self::isCanonicalKey($plaintext) || !preg_match('/\\\\A[a-f0-9]{64}\\\\z/D', $verifier)) {
            return false;
        }

        $expectedVerifier = self::createVerifierForExistingKey($plaintext, $pepper, $pepperKeyId);

        return hash_equals($expectedVerifier, $verifier);
    }

    private static function isCanonicalKey(string $plaintext): bool
    {
        if (strlen($plaintext) !== strlen(self::PREFIX) + 43 || !str_starts_with($plaintext, self::PREFIX)) {
            return false;
        }

        $encodedSecret = substr($plaintext, strlen(self::PREFIX));
        $allowedCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

        if (strspn($encodedSecret, $allowedCharacters) !== 43) {
            return false;
        }

        $decodedSecret = base64_decode(strtr($encodedSecret, '-_', '+/') . '=', true);

        if ($decodedSecret === false || strlen($decodedSecret) !== self::ENTROPY_BYTES) {
            return false;
        }

        $canonicalEncoding = rtrim(strtr(base64_encode($decodedSecret), '+/', '-_'), '=');

        return hash_equals($canonicalEncoding, $encodedSecret);
    }

    private static function createVerifier(string $plaintext, string $pepper, string $pepperKeyId): string
    {
        return hash_hmac('sha256', $pepperKeyId . chr(0) . $plaintext, $pepper);
    }

    private static function assertPepper(string $pepper): void
    {
        if (strlen($pepper) < self::MIN_PEPPER_BYTES) {
            throw new InvalidArgumentException('The licence-key pepper must contain at least 32 bytes.');
        }
    }

    private static function assertPepperKeyId(string $pepperKeyId): void
    {
        if (!preg_match('/\\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\\z/D', $pepperKeyId)) {
            throw new InvalidArgumentException('The licence-key pepper identifier has an invalid format.');
        }
    }
}
