<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use InvalidArgumentException;
use JsonException;
use Tlms\Domain\Licensing\LicenseKeyPepperRing;
use Tlms\Domain\Licensing\LicenseResponseCipherRing;

final class LicenseKeyMaterialProvider
{
    public function pepperRing(): LicenseKeyPepperRing
    {
        $keys = $this->decodeKeyRing(
            config('tlms.licensing.key_peppers_json'),
            'licence-key pepper',
            32,
            128,
        );
        $activeKeyId = config('tlms.licensing.active_pepper_key_id');

        if (! is_string($activeKeyId) || $activeKeyId === '') {
            throw new InvalidArgumentException('The active licence-key pepper identifier is not configured.');
        }

        return new LicenseKeyPepperRing($keys, $activeKeyId);
    }

    public function responseCipherRing(): LicenseResponseCipherRing
    {
        $keys = $this->decodeKeyRing(
            config('tlms.licensing.response_encryption_keys_json'),
            'issuance response-encryption',
            32,
            32,
        );
        $activeKeyId = config('tlms.licensing.active_response_encryption_key_id');

        if (! is_string($activeKeyId) || $activeKeyId === '') {
            throw new InvalidArgumentException('The active issuance response-encryption key identifier is not configured.');
        }

        return new LicenseResponseCipherRing($keys, $activeKeyId);
    }

    /**
     * @return array<string, string>
     */
    private function decodeKeyRing(mixed $json, string $label, int $minimumBytes, int $maximumBytes): array
    {
        if (! is_string($json) || trim($json) === '') {
            throw new InvalidArgumentException('The '.$label.' key ring is not configured.');
        }

        try {
            $encodedKeys = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The '.$label.' key ring configuration is invalid.', previous: $exception);
        }

        if (! is_array($encodedKeys) || $encodedKeys === []) {
            throw new InvalidArgumentException('The '.$label.' key ring configuration is invalid.');
        }

        $keys = [];
        foreach ($encodedKeys as $keyId => $encodedKey) {
            if (
                ! is_string($keyId)
                || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $keyId) !== 1
                || ! is_string($encodedKey)
                || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encodedKey) !== 1
            ) {
                throw new InvalidArgumentException('The '.$label.' key ring contains an invalid entry.');
            }

            $decoded = base64_decode(
                strtr($encodedKey, '-_', '+/').str_repeat('=', (4 - strlen($encodedKey) % 4) % 4),
                true,
            );

            if (
                $decoded === false
                || strlen($decoded) < $minimumBytes
                || strlen($decoded) > $maximumBytes
                || self::base64UrlEncode($decoded) !== $encodedKey
            ) {
                throw new InvalidArgumentException('The '.$label.' key ring contains invalid key material.');
            }

            $keys[$keyId] = $decoded;
        }

        return $keys;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
