<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tlms\Domain\Licensing\LicenseIssuanceFingerprint;

final class LicenseIssuanceFingerprintTest extends TestCase
{
    public function test_fingerprint_is_stable_when_object_key_order_differs(): void
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

        self::assertSame($first, $second);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first);
    }

    public function test_fingerprint_is_scoped_to_vendor_and_actor(): void
    {
        $payload = ['customer_organization_id' => 'customer-1'];
        $fingerprint = LicenseIssuanceFingerprint::create('vendor-1', 'actor-1', $payload);

        self::assertNotSame($fingerprint, LicenseIssuanceFingerprint::create('vendor-2', 'actor-1', $payload));
        self::assertNotSame($fingerprint, LicenseIssuanceFingerprint::create('vendor-1', 'actor-2', $payload));
    }

    public function test_fingerprint_rejects_non_json_payload_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LicenseIssuanceFingerprint::create('vendor-1', 'actor-1', ['invalid' => new \stdClass()]);
    }

    public function test_fingerprint_rejects_empty_scope_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LicenseIssuanceFingerprint::create('', 'actor-1', []);
    }
}
