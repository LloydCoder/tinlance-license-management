<?php

declare(strict_types=1);

namespace Tlms\\Domain\\Licensing;

use InvalidArgumentException;
use LogicException;

final class Ed25519KeyPair
{
    private ?string $secretKey;

    private function __construct(
        private readonly string $publicKey,
        string $secretKey,
    ) {
        $this->secretKey = $secretKey;
    }

    public static function generate(): self
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);

        try {
            return self::fromSeed($seed);
        } finally {
            sodium_memzero($seed);
        }
    }

    public static function fromSeed(string $seed): self
    {
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException('An Ed25519 seed must contain exactly 32 bytes.');
        }

        $keyPair = sodium_crypto_sign_seed_keypair($seed);

        try {
            return new self(
                sodium_crypto_sign_publickey($keyPair),
                sodium_crypto_sign_secretkey($keyPair),
            );
        } finally {
            sodium_memzero($keyPair);
            sodium_memzero($seed);
        }
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function keyId(): string
    {
        return self::keyIdForPublicKey($this->publicKey);
    }

    public function sign(string $message): string
    {
        if (! is_string($this->secretKey) || strlen($this->secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new LogicException('The Ed25519 signing key has been destroyed.');
        }

        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    public function destroy(): void
    {
        if (is_string($this->secretKey) && $this->secretKey !== '') {
            sodium_memzero($this->secretKey);
        }

        $this->secretKey = null;
    }

    public static function keyIdForPublicKey(string $publicKey): string
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 public key must contain exactly 32 bytes.');
        }

        $descriptor = [
            'keytype' => 'ed25519',
            'scheme' => 'ed25519',
            'keyval' => [
                'public' => self::base64UrlEncode($publicKey),
            ],
        ];

        return hash('sha256', CanonicalJson::encode($descriptor));
    }

    public function __debugInfo(): array
    {
        return [
            'key_id' => $this->keyId(),
            'public_key' => self::base64UrlEncode($this->publicKey),
            'secret_key' => '[REDACTED]',
        ];
    }

    /**
     * Signing keypairs must never be serialized into sessions, queues, caches or logs.
     *
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Ed25519 private key material cannot be serialized.');
    }

    /**
     * @param array<string, mixed> $data
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Ed25519 private key material cannot be unserialized.');
    }

    public function __destruct()
    {
        $this->destroy();
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
