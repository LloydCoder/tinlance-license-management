<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use Tlms\Domain\Licensing\LicenseKey;
use Tlms\Domain\Licensing\LicenseKeyPepperRing;

final class LicenseKeyPepperRingTest
{
    private const OLD_PEPPER = 'old-test-pepper-material-at-least-32-bytes-long';
    private const NEW_PEPPER = 'new-test-pepper-material-at-least-32-bytes-long';

    public function testValidOldKeyIsVerifiedAndRehashedToTheActivePepper(): void
    {
        $issued = LicenseKey::issue(self::OLD_PEPPER, 'pepper-v1');
        $ring = new LicenseKeyPepperRing([
            'pepper-v1' => self::OLD_PEPPER,
            'pepper-v2' => self::NEW_PEPPER,
        ], 'pepper-v2');

        $result = $ring->verifyAndUpgrade($issued->plaintext, $issued->verifier, $issued->pepperKeyId);

        expectTrue($result->valid);
        expectSame('pepper-v2', $result->pepperKeyId);
        expectTrue($result->replacementVerifier !== null);
        expectTrue(LicenseKey::verify($issued->plaintext, $result->replacementVerifier, self::NEW_PEPPER, 'pepper-v2'));
    }

    public function testCurrentPepperVerificationDoesNotRequireRehash(): void
    {
        $issued = LicenseKey::issue(self::NEW_PEPPER, 'pepper-v2');
        $ring = new LicenseKeyPepperRing(['pepper-v2' => self::NEW_PEPPER], 'pepper-v2');

        $result = $ring->verifyAndUpgrade($issued->plaintext, $issued->verifier, $issued->pepperKeyId);

        expectTrue($result->valid);
        expectSame('pepper-v2', $result->pepperKeyId);
        expectSame(null, $result->replacementVerifier);
    }

    public function testUnknownStoredPepperIdFailsClosed(): void
    {
        $ring = new LicenseKeyPepperRing(['pepper-v2' => self::NEW_PEPPER], 'pepper-v2');

        $result = $ring->verifyAndUpgrade('tlms_' . str_repeat('A', 43), str_repeat('a', 64), 'retired-v0');

        expectFalse($result->valid);
        expectSame(null, $result->replacementVerifier);
    }

    public function testTamperedKeyNeverProducesReplacementVerifier(): void
    {
        $issued = LicenseKey::issue(self::OLD_PEPPER, 'pepper-v1');
        $ring = new LicenseKeyPepperRing([
            'pepper-v1' => self::OLD_PEPPER,
            'pepper-v2' => self::NEW_PEPPER,
        ], 'pepper-v2');

        $result = $ring->verifyAndUpgrade($issued->plaintext . 'x', $issued->verifier, $issued->pepperKeyId);

        expectFalse($result->valid);
        expectSame(null, $result->replacementVerifier);
    }
}
