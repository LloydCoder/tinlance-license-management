<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\LicenseKeyConfigurationException;
use App\Models\License;
use App\Models\PlanVersion;
use App\Services\Licensing\LicenseSigningKeyMaterialProvider;
use App\Services\Licensing\LicenseSigningService;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;
use Tlms\Domain\Licensing\CanonicalJson;
use Tlms\Domain\Licensing\Ed25519KeyPair;
use Tlms\Domain\Licensing\SigningTrustMetadata;

final class LicenseSigningServiceTest extends TestCase
{
    public function testSignedLicenseClaimsBindTheImmutablePlanSnapshotWithoutCredentialMaterial(): void
    {
        $fixture = $this->configureSigningKey();

        try {
            [$license, $planVersion] = $this->models();
            $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
            $service = new LicenseSigningService(new LicenseSigningKeyMaterialProvider);

            $envelope = $service->signLicense($license, $planVersion, $fixture['trust'], $now);
            $claims = get_object_vars($envelope['signed']);

            expectTrue($fixture['trust']->verifyRoleEnvelope($envelope, 'license', $now));
            expectSame((string) $license->getKey(), $claims['license_id']);
            expectSame((string) $planVersion->getKey(), $claims['plan_version_id']);
            expectSame($planVersion->snapshot_sha256, $claims['plan_snapshot_sha256']);
            expectSame(CanonicalJson::normalizeObject($planVersion->entitlements), $claims['entitlements']);
            expectSame((int) $license->version, $claims['aggregate_version']);
            expectFalse(array_key_exists('key_verifier', $claims));
            expectFalse(array_key_exists('pepper_key_id', $claims));
            expectFalse(array_key_exists('response_ciphertext', $claims));

            $tampered = $envelope;
            $tampered['signed']->status = 'revoked';
            expectFalse($fixture['trust']->verifyRoleEnvelope($tampered, 'license', $now));
        } finally {
            sodium_memzero($fixture['seed']);
        }
    }

    public function testSigningRejectsAPlanVersionThatDoesNotBelongToTheLicense(): void
    {
        $fixture = $this->configureSigningKey();

        try {
            [$license, $planVersion] = $this->models();
            $license->plan_version_id = (string) Str::ulid();

            expectThrows(
                fn () => (new LicenseSigningService(new LicenseSigningKeyMaterialProvider))
                    ->signLicense($license, $planVersion, $fixture['trust'], new DateTimeImmutable('2030-01-01T00:00:00Z')),
                InvalidArgumentException::class,
            );
        } finally {
            sodium_memzero($fixture['seed']);
        }
    }

    public function testSigningRejectsAnUnpublishedPlanVersion(): void
    {
        $fixture = $this->configureSigningKey();

        try {
            [$license, $planVersion] = $this->models();
            $planVersion->status = 'draft';

            expectThrows(
                fn () => (new LicenseSigningService(new LicenseSigningKeyMaterialProvider))
                    ->signLicense($license, $planVersion, $fixture['trust'], new DateTimeImmutable('2030-01-01T00:00:00Z')),
                InvalidArgumentException::class,
            );
        } finally {
            sodium_memzero($fixture['seed']);
        }
    }

    public function testSigningRejectsAnInvalidPlanSnapshotDigest(): void
    {
        $fixture = $this->configureSigningKey();

        try {
            [$license, $planVersion] = $this->models();
            $planVersion->snapshot_sha256 = 'invalid';

            expectThrows(
                fn () => (new LicenseSigningService(new LicenseSigningKeyMaterialProvider))
                    ->signLicense($license, $planVersion, $fixture['trust'], new DateTimeImmutable('2030-01-01T00:00:00Z')),
                InvalidArgumentException::class,
            );
        } finally {
            sodium_memzero($fixture['seed']);
        }
    }

    public function testActiveSigningKeyMustBePresentInTheTrustedLicenseRole(): void
    {
        $fixture = $this->configureSigningKey();
        $otherKey = Ed25519KeyPair::generate();

        try {
            [$license, $planVersion] = $this->models();
            $untrustedMetadata = $this->trustFor($otherKey);

            expectThrows(
                fn () => (new LicenseSigningService(new LicenseSigningKeyMaterialProvider))
                    ->signLicense($license, $planVersion, $untrustedMetadata, new DateTimeImmutable('2030-01-01T00:00:00Z')),
                LicenseKeyConfigurationException::class,
            );
        } finally {
            sodium_memzero($fixture['seed']);
            $otherKey->destroy();
        }
    }

    public function testSigningKeyRingRejectsMalformedSeedMaterial(): void
    {
        config([
            'tlms.licensing.signing_private_seeds_json' => json_encode(['license-key-1' => 'not-base64url'], JSON_THROW_ON_ERROR),
            'tlms.licensing.active_signing_key_id' => 'license-key-1',
        ]);

        $key = Ed25519KeyPair::generate();
        try {
            expectThrows(
                fn () => (new LicenseSigningKeyMaterialProvider)->activeLicenseKeyPair($this->trustFor($key)),
                LicenseKeyConfigurationException::class,
            );
        } finally {
            $key->destroy();
        }
    }

    public function testSigningKeyRingRequiresTheConfiguredActiveKey(): void
    {
        config([
            'tlms.licensing.signing_private_seeds_json' => json_encode(['license-key-1' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')], JSON_THROW_ON_ERROR),
            'tlms.licensing.active_signing_key_id' => 'missing-key',
        ]);

        $key = Ed25519KeyPair::generate();
        try {
            expectThrows(
                fn () => (new LicenseSigningKeyMaterialProvider)->activeLicenseKeyPair($this->trustFor($key)),
                LicenseKeyConfigurationException::class,
            );
        } finally {
            $key->destroy();
        }
    }

    public function testExpiredSigningTrustMetadataFailsClosedBeforeLoadingKeyMaterial(): void
    {
        $fixture = $this->configureSigningKey();
        $expiredTrust = $this->trustFor(
            $this->publicKeyOnly($fixture['key_id'], $fixture['public_key']),
            '2029-12-31T23:59:59Z',
        );

        try {
            [$license, $planVersion] = $this->models();

            expectThrows(
                fn () => (new LicenseSigningService(new LicenseSigningKeyMaterialProvider))
                    ->signLicense($license, $planVersion, $expiredTrust, new DateTimeImmutable('2030-01-01T00:00:00Z')),
                LicenseKeyConfigurationException::class,
            );
        } finally {
            sodium_memzero($fixture['seed']);
        }
    }

    /**
     * @return array{trust: SigningTrustMetadata, seed: string, key_id: string, public_key: string}
     */
    private function configureSigningKey(): array
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $keyPair = Ed25519KeyPair::fromSeed($seed);
        $keyId = $keyPair->keyId();
        $publicKey = $keyPair->publicKey();
        $trust = $this->trustFor($keyPair);
        $keyPair->destroy();

        config([
            'tlms.licensing.signing_private_seeds_json' => json_encode([
                $keyId => rtrim(strtr(base64_encode($seed), '+/', '-_'), '='),
            ], JSON_THROW_ON_ERROR),
            'tlms.licensing.active_signing_key_id' => $keyId,
        ]);

        return [
            'trust' => $trust,
            'seed' => $seed,
            'key_id' => $keyId,
            'public_key' => $publicKey,
        ];
    }

    private function trustFor(Ed25519KeyPair $keyPair, string $expires = '2035-01-01T00:00:00Z'): SigningTrustMetadata
    {
        return $this->trustForPublicKey($keyPair->keyId(), $keyPair->publicKey(), $expires);
    }

    private function publicKeyOnly(string $keyId, string $publicKey): Ed25519KeyPair
    {
        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = Ed25519KeyPair::fromSeed($seed);
        sodium_memzero($seed);

        if (! hash_equals($keyId, $pair->keyId()) || ! hash_equals($publicKey, $pair->publicKey())) {
            $pair->destroy();
            throw new InvalidArgumentException('The test public key material is inconsistent.');
        }

        return $pair;
    }

    private function trustForPublicKey(
        string $keyId,
        string $publicKey,
        string $expires = '2035-01-01T00:00:00Z',
    ): SigningTrustMetadata {
        $keys = new \stdClass;
        $keys->{$keyId} = CanonicalJson::normalizeObject([
            'keytype' => 'ed25519',
            'scheme' => 'ed25519',
            'keyval' => ['public' => bin2hex($publicKey)],
        ]);

        $roles = new \stdClass;
        $roles->root = CanonicalJson::normalizeObject(['keyids' => [$keyId], 'threshold' => 1]);
        $roles->license = CanonicalJson::normalizeObject(['keyids' => [$keyId], 'threshold' => 1]);

        return SigningTrustMetadata::fromSigned(CanonicalJson::normalizeObject([
            '_type' => 'root',
            'schema_version' => 1,
            'version' => 1,
            'expires' => $expires,
            'keys' => $keys,
            'roles' => $roles,
        ]));
    }

    /**
     * @return array{License, PlanVersion}
     */
    private function models(): array
    {
        $vendorId = (string) Str::ulid();
        $productId = (string) Str::ulid();
        $planId = (string) Str::ulid();
        $planVersionId = (string) Str::ulid();

        $planVersion = new PlanVersion;
        $planVersion->forceFill([
            'id' => $planVersionId,
            'plan_id' => $planId,
            'product_id' => $productId,
            'vendor_account_id' => $vendorId,
            'version_number' => 3,
            'status' => 'published',
            'billing_model' => 'subscription',
            'currency' => 'USD',
            'price_minor' => 9900,
            'billing_interval' => 'month',
            'trial_days' => 14,
            'maintenance_days' => 365,
            'entitlements' => ['seats' => 5, 'offline' => true],
            'snapshot_sha256' => str_repeat('a', 64),
        ]);

        $license = new License;
        $license->forceFill([
            'id' => (string) Str::ulid(),
            'vendor_account_id' => $vendorId,
            'customer_organization_id' => (string) Str::ulid(),
            'product_id' => $productId,
            'plan_version_id' => $planVersionId,
            'public_reference' => 'LIC-'.Str::ulid(),
            'key_verifier' => str_repeat('b', 64),
            'pepper_key_id' => 'pepper-v1',
            'status' => 'issued',
            'starts_at' => Carbon::parse('2030-01-01T00:00:00Z'),
            'expires_at' => Carbon::parse('2031-01-01T00:00:00Z'),
            'grace_until' => Carbon::parse('2031-01-31T00:00:00Z'),
            'version' => 1,
            'created_by_user_id' => (string) Str::ulid(),
            'created_at' => Carbon::parse('2029-12-31T23:00:00Z'),
        ]);

        return [$license, $planVersion];
    }
}
