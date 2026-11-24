<?php

declare(strict_types=1);

namespace Tlms\Domain\Licensing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use stdClass;

final class SigningTrustMetadata
{
    /**
     * @param  array<string, string>  $publicKeys Raw Ed25519 public keys keyed by key ID.
     * @param  array<string, array{keyids: list<string>, threshold: int}>  $roles
     */
    private function __construct(
        public readonly int $version,
        public readonly DateTimeImmutable $expiresAt,
        private readonly array $publicKeys,
        private readonly array $roles,
    ) {}

    public static function fromSigned(stdClass $signed): self
    {
        $data = get_object_vars($signed);

        if (($data['_type'] ?? null) !== 'root' || ($data['schema_version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported TLMS signing trust metadata type or schema version.');
        }

        $version = $data['version'] ?? null;
        if (! is_int($version) || $version < 1 || $version > 9007199254740991) {
            throw new InvalidArgumentException('Signing trust metadata version must be a positive safe integer.');
        }

        $expires = $data['expires'] ?? null;
        if (! is_string($expires)) {
            throw new InvalidArgumentException('Signing trust metadata must contain a UTC expiry timestamp.');
        }
        $expiresAt = self::parseExpiry($expires);

        $keysObject = $data['keys'] ?? null;
        $rolesObject = $data['roles'] ?? null;
        if (! ($keysObject instanceof stdClass) || ! ($rolesObject instanceof stdClass)) {
            throw new InvalidArgumentException('Signing trust metadata keys and roles must be JSON objects.');
        }

        $publicKeys = [];
        foreach (get_object_vars($keysObject) as $keyId => $descriptor) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $keyId) !== 1) {
                throw new InvalidArgumentException('Signing trust metadata contains an invalid key ID.');
            }

            if (! ($descriptor instanceof stdClass)) {
                throw new InvalidArgumentException('Signing trust key descriptors must be JSON objects.');
            }

            $keyData = get_object_vars($descriptor);
            $keyValue = $keyData['keyval'] ?? null;
            if (
                count($keyData) !== 3
                || ($keyData['keytype'] ?? null) !== 'ed25519'
                || ($keyData['scheme'] ?? null) !== 'ed25519'
                || ! ($keyValue instanceof stdClass)
            ) {
                throw new InvalidArgumentException('Signing trust key descriptors must use the supported Ed25519 format.');
            }

            $keyValueData = get_object_vars($keyValue);
            $publicHex = $keyValueData['public'] ?? null;
            if (count($keyValueData) !== 1 || ! is_string($publicHex) || preg_match('/\A[a-f0-9]{64}\z/D', $publicHex) !== 1) {
                throw new InvalidArgumentException('Signing trust metadata contains an invalid Ed25519 public key.');
            }

            $publicKey = hex2bin($publicHex);
            if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new InvalidArgumentException('Signing trust metadata contains an invalid Ed25519 public key.');
            }

            if (! hash_equals($keyId, Ed25519KeyPair::keyIdForPublicKey($publicKey))) {
                throw new InvalidArgumentException('Signing trust metadata key ID does not match its public key.');
            }

            $publicKeys[$keyId] = $publicKey;
        }

        if ($publicKeys === []) {
            throw new InvalidArgumentException('Signing trust metadata must contain at least one public key.');
        }

        /** @var array<string, array{keyids: list<string>, threshold: int}> $roles */\n        $roles = [];
        foreach (get_object_vars($rolesObject) as $roleName => $roleDescriptor) {
            if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $roleName) !== 1) {
                throw new InvalidArgumentException('Signing trust metadata contains an invalid role name.');
            }

            if (! ($roleDescriptor instanceof stdClass)) {
                throw new InvalidArgumentException('Signing trust role descriptors must be JSON objects.');
            }

            $roleData = get_object_vars($roleDescriptor);
            $keyIds = $roleData['keyids'] ?? null;
            $threshold = $roleData['threshold'] ?? null;
            if (! is_array($keyIds) || ! array_is_list($keyIds) || ! is_int($threshold)) {
                throw new InvalidArgumentException('Signing trust roles must define a key ID list and integer threshold.');
            }

            $uniqueKeyIds = [];
            foreach ($keyIds as $keyId) {
                if (
                    ! is_string($keyId)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $keyId) !== 1
                    || ! isset($publicKeys[$keyId])
                    || isset($uniqueKeyIds[$keyId])
                ) {
                    throw new InvalidArgumentException('Signing trust role references an invalid, unknown or duplicate key ID.');
                }

                $uniqueKeyIds[$keyId] = true;
            }

            if ($threshold < 1 || $threshold > count($uniqueKeyIds)) {
                throw new InvalidArgumentException('Signing trust role threshold must be within its distinct key count.');
            }

            $roles[$roleName] = [
                'keyids' => array_keys($uniqueKeyIds),
                'threshold' => $threshold,
            ];
        }

        if (! isset($roles['root'], $roles['license'])) {
            throw new InvalidArgumentException('Signing trust metadata must define root and license roles.');
        }

        return new self($version, $expiresAt, $publicKeys, $roles);
    }

    public function isCurrentAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt > $now;
    }

    /**
     * @return array<string, string>
     */
    public function publicKeysForRole(string $role): array
    {
        $policy = $this->roles[$role] ?? null;
        if ($policy === null) {
            throw new InvalidArgumentException('The requested signing role is not defined.');
        }

        $trustedKeys = [];
        foreach ($policy['keyids'] as $keyId) {
            $trustedKeys[$keyId] = $this->publicKeys[$keyId];
        }

        return $trustedKeys;
    }

    public function thresholdForRole(string $role): int
    {
        $policy = $this->roles[$role] ?? null;
        if ($policy === null) {
            throw new InvalidArgumentException('The requested signing role is not defined.');
        }

        return $policy['threshold'];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function verifyRoleEnvelope(array $envelope, string $role, DateTimeImmutable $now): bool
    {
        if ($this->expiresAt <= $now || ! isset($this->roles[$role])) {
            return false;
        }

        return Ed25519Envelope::verify(
            $envelope,
            $this->publicKeysForRole($role),
            $this->thresholdForRole($role),
        );
    }

    private static function parseExpiry(string $expires): DateTimeImmutable
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $expires) !== 1) {
            throw new InvalidArgumentException('Signing trust metadata expiry must use the canonical UTC timestamp format.');
        }

        $expiresAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $expires,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $expiresAt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $expiresAt->format('Y-m-d\TH:i:s\Z') !== $expires
        ) {
            throw new InvalidArgumentException('Signing trust metadata expiry is not a valid UTC timestamp.');
        }

        return $expiresAt;
    }
}
