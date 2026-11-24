<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Tlms\Domain\Licensing\CanonicalJson;
use Tlms\Domain\Licensing\Ed25519Envelope;
use Tlms\Domain\Licensing\Ed25519KeyPair;
use Tlms\Domain\Licensing\SigningTrustMetadata;
use Tlms\Domain\Licensing\SigningTrustMetadataVerifier;

final class SigningTrustMetadataTest
{
    public function testTrustedMetadataVerifiesDelegatedLicenseRole(): void
    {
        $root = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $metadata = $this->metadata([$root], [$license]);
        $trust = SigningTrustMetadata::fromSigned($metadata);
        $licenseEnvelope = $this->signMetadata(
            CanonicalJson::normalizeObject(['license_id' => 'license-trust-1']),
            [$license],
        );

        expectTrue($trust->verifyRoleEnvelope(
            $licenseEnvelope,
            'license',
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        ));
    }

    public function testRootRotationRequiresOldAndNewRootThresholds(): void
    {
        $oldRootA = Ed25519KeyPair::generate();
        $oldRootB = Ed25519KeyPair::generate();
        $newRootA = Ed25519KeyPair::generate();
        $newRootB = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();

        $current = SigningTrustMetadata::fromSigned(
            $this->metadata([$oldRootA, $oldRootB], [$license], version: 1, rootThreshold: 2),
        );
        $candidate = $this->metadata([$newRootA, $newRootB], [$license], version: 2, rootThreshold: 2);
        $envelope = $this->signMetadata($candidate, [$oldRootA, $oldRootB, $newRootA, $newRootB]);

        $rotated = SigningTrustMetadataVerifier::verifyRootRotation($envelope, $current);

        if (! ($rotated instanceof SigningTrustMetadata)) {
            throw new RuntimeException('The valid root rotation was rejected.');
        }
        expectSame(2, $rotated->version);
    }

    public function testRootRotationRejectsMissingPreviousRootThreshold(): void
    {
        $oldRootA = Ed25519KeyPair::generate();
        $oldRootB = Ed25519KeyPair::generate();
        $newRootA = Ed25519KeyPair::generate();
        $newRootB = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();

        $current = SigningTrustMetadata::fromSigned(
            $this->metadata([$oldRootA, $oldRootB], [$license], rootThreshold: 2),
        );
        $candidate = $this->metadata([$newRootA, $newRootB], [$license], version: 2, rootThreshold: 2);
        $envelope = $this->signMetadata($candidate, [$newRootA, $newRootB]);

        expectSame(
            null,
            SigningTrustMetadataVerifier::verifyRootRotation($envelope, $current),
        );
    }

    public function testRootRotationRejectsMissingNewRootThreshold(): void
    {
        $oldRootA = Ed25519KeyPair::generate();
        $oldRootB = Ed25519KeyPair::generate();
        $newRootA = Ed25519KeyPair::generate();
        $newRootB = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();

        $current = SigningTrustMetadata::fromSigned(
            $this->metadata([$oldRootA, $oldRootB], [$license], rootThreshold: 2),
        );
        $candidate = $this->metadata([$newRootA, $newRootB], [$license], version: 2, rootThreshold: 2);
        $envelope = $this->signMetadata($candidate, [$oldRootA, $oldRootB]);

        expectSame(
            null,
            SigningTrustMetadataVerifier::verifyRootRotation($envelope, $current),
        );
    }

    public function testRootRotationRequiresExactlyTheNextVersion(): void
    {
        $oldRoot = Ed25519KeyPair::generate();
        $newRoot = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $current = SigningTrustMetadata::fromSigned($this->metadata([$oldRoot], [$license], version: 1));
        $candidate = $this->metadata([$newRoot], [$license], version: 3);
        $envelope = $this->signMetadata($candidate, [$oldRoot, $newRoot]);

        expectSame(
            null,
            SigningTrustMetadataVerifier::verifyRootRotation($envelope, $current),
        );
    }

    public function testRootRotationChainAllowsExpiredIntermediateAndRequiresFreshFinalRoot(): void
    {
        $oldRoot = Ed25519KeyPair::generate();
        $intermediateRoot = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $current = SigningTrustMetadata::fromSigned($this->metadata([$oldRoot], [$license], version: 1));
        $intermediate = $this->metadata(
            [$intermediateRoot],
            [$license],
            version: 2,
            expires: '2029-12-31T23:59:59Z',
        );
        $intermediateEnvelope = $this->signMetadata($intermediate, [$oldRoot, $intermediateRoot]);
        $final = $this->metadata(
            [$intermediateRoot],
            [$license],
            version: 3,
            expires: '2035-01-01T00:00:00Z',
        );
        $finalEnvelope = $this->signMetadata($final, [$intermediateRoot]);

        $rotated = SigningTrustMetadataVerifier::verifyRootRotationChain(
            [$intermediateEnvelope, $finalEnvelope],
            $current,
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        );

        if (! ($rotated instanceof SigningTrustMetadata)) {
            throw new RuntimeException('The valid sequential root rotation chain was rejected.');
        }
        expectSame(3, $rotated->version);
    }

    public function testRootRotationChainRejectsExpiredFinalMetadata(): void
    {
        $oldRoot = Ed25519KeyPair::generate();
        $newRoot = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $current = SigningTrustMetadata::fromSigned($this->metadata([$oldRoot], [$license], version: 1));
        $candidate = $this->metadata(
            [$newRoot],
            [$license],
            version: 2,
            expires: '2029-12-31T23:59:59Z',
        );
        $envelope = $this->signMetadata($candidate, [$oldRoot, $newRoot]);

        expectSame(
            null,
            SigningTrustMetadataVerifier::verifyRootRotationChain(
                [$envelope],
                $current,
                new DateTimeImmutable('2030-01-01T00:00:00Z'),
            ),
        );
    }

    public function testRootRotationChainCanRecoverFromAnExpiredBootstrapRoot(): void
    {
        $oldRoot = Ed25519KeyPair::generate();
        $newRoot = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $expiredCurrent = SigningTrustMetadata::fromSigned(
            $this->metadata([$oldRoot], [$license], version: 1, expires: '2029-12-31T23:59:59Z'),
        );
        $candidate = $this->metadata([$newRoot], [$license], version: 2);
        $envelope = $this->signMetadata($candidate, [$oldRoot, $newRoot]);

        $rotated = SigningTrustMetadataVerifier::verifyRootRotationChain(
            [$envelope],
            $expiredCurrent,
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        );

        expectTrue($rotated instanceof SigningTrustMetadata);
    }

    public function testAcceptedRootRotationRevokesThePreviousOperationalKey(): void
    {
        $root = Ed25519KeyPair::generate();
        $oldLicense = Ed25519KeyPair::generate();
        $newLicense = Ed25519KeyPair::generate();
        $current = SigningTrustMetadata::fromSigned($this->metadata([$root], [$oldLicense], version: 1));
        $oldLicenseEnvelope = $this->signMetadata(
            CanonicalJson::normalizeObject(['license_id' => 'license-before-rotation']),
            [$oldLicense],
        );
        $candidate = $this->metadata([$root], [$newLicense], version: 2);
        $rootEnvelope = $this->signMetadata($candidate, [$root]);

        $rotated = SigningTrustMetadataVerifier::verifyRootRotation($rootEnvelope, $current);

        if (! ($rotated instanceof SigningTrustMetadata)) {
            throw new RuntimeException('The valid root rotation was rejected.');
        }
        expectTrue($current->verifyRoleEnvelope(
            $oldLicenseEnvelope,
            'license',
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        ));
        expectFalse($rotated->verifyRoleEnvelope(
            $oldLicenseEnvelope,
            'license',
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        ));
    }

    public function testMetadataRejectsRoleReferencesToUnknownKeys(): void
    {
        $root = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $metadata = $this->metadata([$root], [$license]);
        $metadata->roles->license->keyids[] = str_repeat('a', 64);

        expectThrows(
            static fn () => SigningTrustMetadata::fromSigned($metadata),
            InvalidArgumentException::class,
        );
    }

    public function testMetadataRejectsThresholdsLargerThanTheRoleKeySet(): void
    {
        $root = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $metadata = $this->metadata([$root], [$license]);
        $metadata->roles->license->threshold = 2;

        expectThrows(
            static fn () => SigningTrustMetadata::fromSigned($metadata),
            InvalidArgumentException::class,
        );
    }

    public function testMetadataRejectsPublicKeysWhoseKeyIdsDoNotMatch(): void
    {
        $root = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $other = Ed25519KeyPair::generate();
        $metadata = $this->metadata([$root], [$license]);
        $metadata->keys->{$license->keyId()}->keyval->public = bin2hex($other->publicKey());

        expectThrows(
            static fn () => SigningTrustMetadata::fromSigned($metadata),
            InvalidArgumentException::class,
        );
    }

    public function testRoleVerificationRejectsExpiredTrustedMetadata(): void
    {
        $root = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $metadata = $this->metadata(
            [$root],
            [$license],
            expires: '2029-12-31T23:59:59Z',
        );
        $trust = SigningTrustMetadata::fromSigned($metadata);
        $envelope = $this->signMetadata(
            CanonicalJson::normalizeObject(['license_id' => 'expired-trust']),
            [$license],
        );

        expectFalse($trust->verifyRoleEnvelope(
            $envelope,
            'license',
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
        ));
    }

    public function testMetadataRejectsNonCanonicalExpiryTimezone(): void
    {
        $root = Ed25519KeyPair::generate();
        $license = Ed25519KeyPair::generate();
        $metadata = $this->metadata([$root], [$license], expires: '2035-01-01T00:00:00+00:00');

        expectThrows(
            static fn () => SigningTrustMetadata::fromSigned($metadata),
            InvalidArgumentException::class,
        );
    }

    /**
     * @param  list<Ed25519KeyPair>  $rootKeys
     * @param  list<Ed25519KeyPair>  $licenseKeys
     */
    private function metadata(
        array $rootKeys,
        array $licenseKeys,
        int $version = 1,
        int $rootThreshold = 1,
        int $licenseThreshold = 1,
        string $expires = '2035-01-01T00:00:00Z',
    ): \stdClass {
        $allKeys = [];
        foreach (array_merge($rootKeys, $licenseKeys) as $keyPair) {
            $allKeys[$keyPair->keyId()] = $keyPair;
        }

        $keys = new \stdClass;
        foreach ($allKeys as $keyId => $keyPair) {
            $keys->{$keyId} = CanonicalJson::normalizeObject([
                'keytype' => 'ed25519',
                'scheme' => 'ed25519',
                'keyval' => ['public' => bin2hex($keyPair->publicKey())],
            ]);
        }

        $roles = new \stdClass;
        $roles->root = CanonicalJson::normalizeObject([
            'keyids' => array_map(static fn (Ed25519KeyPair $key): string => $key->keyId(), $rootKeys),
            'threshold' => $rootThreshold,
        ]);
        $roles->license = CanonicalJson::normalizeObject([
            'keyids' => array_map(static fn (Ed25519KeyPair $key): string => $key->keyId(), $licenseKeys),
            'threshold' => $licenseThreshold,
        ]);

        return CanonicalJson::normalizeObject([
            '_type' => 'root',
            'schema_version' => 1,
            'version' => $version,
            'expires' => $expires,
            'keys' => $keys,
            'roles' => $roles,
        ]);
    }

    /**
     * @param  non-empty-list<Ed25519KeyPair>  $signers
     * @return array{signed: \stdClass, signatures: list<array{keyid: string, sig: string}>}
     */
    private function signMetadata(\stdClass $metadata, array $signers): array
    {
        $envelope = Ed25519Envelope::sign(get_object_vars($metadata), $signers[0]);
        $message = CanonicalJson::encodeObject($metadata);

        foreach (array_slice($signers, 1) as $signer) {
            $envelope['signatures'][] = [
                'keyid' => $signer->keyId(),
                'sig' => bin2hex($signer->sign($message)),
            ];
        }

        return $envelope;
    }
}
