<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use Tlms\Domain\Licensing\LicenseIssuanceFingerprint;

final class LicenseIssuanceFingerprintTest
{
    public function testFingerprintIsStableWhenObjectKeyOrderDiffers(): void
    {
        $first = LicenseIssuanceFingerprint::create('vendor-1', 'actor-1', [
            'customer_organization_id' => 'customer-1',
            'product_id' => 'product-1',
            'plan_version_id' => 'version-1',
            'starts_at' => null,
            'expires_at' => '2027-01-01T00:00:00Z',
            'grace_until' => null,
        ]);
        $second = LicenseIssuanceFingerprint::create('vendor-1', 'actor-1', [
            'grace_until' => null,
            'expires_at' => '2027-01-01T00:00:00Z',
            'starts_at' => null,
            'plan_version_id' => 'version-1',
            'product_id' => 'product-1',
            'customer_organization_id' => 'customer-1',
        ]);

        expectSame($first, $second);
        expectSame(64, strlen($first));
    }

    public function testFingerprintIsScopedToVendorAndActor(): void
    {
        $payload = ['customer_organization_id' => 'customer-1'];
        $fingerprint = LicenseIssuanceFingerprint::create('vendor-1', 'actor-1', $payload);

        expectFalse(hash_equals($fingerprint, LicenseIssuanceFingerprint::create('vendor-2', 'actor-1', $payload)));
        expectFalse(hash_equals($fingerprint, LicenseIssuanceFingerprint::create('vendor-1', 'actor-2', $payload)));
    }

    public function testFingerprintRejectsNonJsonPayloadValues(): void
    {
        expectThrows(
            static fn () => LicenseIssuanceFingerprint::create('vendor-1', 'actor-1', ['invalid' => new \stdClass]),
            InvalidArgumentException::class,
        );
    }

    public function testFingerprintRejectsEmptyScopeIdentifiers(): void
    {
        expectThrows(
            static fn () => LicenseIssuanceFingerprint::create('', 'actor-1', []),
            InvalidArgumentException::class,
        );
    }
}
