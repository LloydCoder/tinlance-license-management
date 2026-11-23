<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use InvalidArgumentException;
use JsonException;
use SodiumException;

/**
 * Authenticated encryption for short-lived issuance response secrets.
 *
 * Keys are independent of Laravel APP_KEY and are indexed by immutable IDs.
 * The caller must enforce the ciphertext retention/expiry policy.
 */
final readonly class LicenseResponseCipherRing
{
    private const VERSION = 1;

    private const AEAD_MATERIAL_LENGTH = 32;

    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    /**
     * @param  array<string, string>  $keys  Raw 32-byte AEAD keys indexed by immutable key ID.
     */
    public function __construct(
        private array $keys,
        private string $activeKeyId,
    ) {
        if ($keys === [] || ! array_key_exists($activeKeyId, $keys)) {
            throw new InvalidArgumentException('The active response-encryption key must exist in the configured key ring.');
        }

        foreach ($keys as $keyId => $key) {
            self::assertKeyId($keyId);
            if (strlen($key) !== self::AEAD_MATERIAL_LENGTH) {
                throw new InvalidArgumentException('Every response-encryption key must contain exactly 32 bytes.');
            }
        }
    }

    /**
     * @throws SodiumException
     * @throws JsonException
     */
    public function encrypt(string $plaintext, string $context): string
    {
        self::assertContext($context);

        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::associatedData($this->activeKeyId, $context),
            $nonce,
            $this->keys[$this->activeKeyId],
        );

        return self::encode([
            'v' => self::VERSION,
            'kid' => $this->activeKeyId,
            'nonce' => self::base64UrlEncode($nonce),
            'ciphertext' => self::base64UrlEncode($ciphertext),
        ]);
    }

    /**
     * @throws SodiumException
     * @throws JsonException
     */
    public function decrypt(string $envelope, string $context): string
    {
        self::assertContext($context);
        $decoded = json_decode(self::base64UrlDecode($envelope), true, 8, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)
            || array_keys($decoded) !== ['v', 'kid', 'nonce', 'ciphertext']
            || $decoded['v'] !== self::VERSION
            || ! is_string($decoded['kid'])
            || ! is_string($decoded['nonce'])
            || ! is_string($decoded['ciphertext'])) {
            throw new InvalidArgumentException('The encrypted issuance response envelope is invalid.');
        }

        $keyId = $decoded['kid'];
        if (! array_key_exists($keyId, $this->keys)) {
            throw new InvalidArgumentException('The encrypted issuance response uses an unavailable key.');
        }

        $nonce = self::base64UrlDecode($decoded['nonce']);
        $ciphertext = self::base64UrlDecode($decoded['ciphertext']);
        if (strlen($nonce) !== self::NONCE_BYTES || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new InvalidArgumentException('The encrypted issuance response envelope is invalid.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            self::associatedData($keyId, $context),
            $nonce,
            $this->keys[$keyId],
        );

        if ($plaintext === false) {
            throw new InvalidArgumentException('The encrypted issuance response could not be authenticated.');
        }

        return $plaintext;
    }

    private static function associatedData(string $keyId, string $context): string
    {
        return 'TLMS:license-issuance-response:v1:'.$keyId.':'.$context;
    }

    private static function assertContext(string $context): void
    {
        if ($context === '' || strlen($context) > 512 || preg_match('/[\x00-\x1F\x7F]/', $context) === 1) {
            throw new InvalidArgumentException('The encrypted issuance response context is invalid.');
        }
    }

    private static function assertKeyId(string $keyId): void
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $keyId) !== 1) {
            throw new InvalidArgumentException('The response-encryption key identifier is invalid.');
        }
    }

    /**
     * @param  array{v:int,kid:string,nonce:string,ciphertext:string}  $value
     *
     * @throws JsonException
     */
    private static function encode(array $value): string
    {
        return self::base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            throw new InvalidArgumentException('The encrypted issuance response encoding is invalid.');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false || self::base64UrlEncode($decoded) !== $value) {
            throw new InvalidArgumentException('The encrypted issuance response encoding is invalid.');
        }

        return $decoded;
    }
}
