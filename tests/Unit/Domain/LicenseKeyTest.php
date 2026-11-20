<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use Tlms\Domain\Licensing\LicenseKey;

final class LicenseKeyTest
{
    private const PEPPER = 'test-only-pepper-material-at-least-32-bytes-long';

    public function testIssueProducesAHighEntropyPrefixedKeyAndVerifier(): void
    {
        $issued = LicenseKey::issue(self::PEPPER);

        expectTrue(str_starts_with($issued->plaintext, 'tlms_'));
        expectSame(43, strlen(substr($issued->plaintext, strlen('tlms_'))));
        expectSame(64, strlen($issued->verifier));
        expectFalse(str_contains($issued->verifier, $issued->plaintext));
    }

    public function testIssuedKeyCanBeVerified(): void
    {
        $issued = LicenseKey::issue(self::PEPPER);

        expectTrue(LicenseKey::verify($issued->plaintext, $issued->verifier, self::PEPPER));
    }

    public function testTamperedKeyIsRejected(): void
    {
        $issued = LicenseKey::issue(self::PEPPER);
        $tampered = $issued->plaintext . 'x';

        expectFalse(LicenseKey::verify($tampered, $issued->verifier, self::PEPPER));
    }

    public function testWrongPepperIsRejected(): void
    {
        $issued = LicenseKey::issue(self::PEPPER);

        expectFalse(LicenseKey::verify($issued->plaintext, $issued->verifier, 'a-different-pepper-material-that-is-long-enough'));
    }

    public function testWeakPepperIsRejectedWhenIssuing(): void
    {
        expectThrows(
            static fn () => LicenseKey::issue('too-short'),
            InvalidArgumentException::class,
        );
    }

    public function testMalformedKeyIsRejectedWithoutThrowing(): void
    {
        expectFalse(LicenseKey::verify('not-a-tlms-key', str_repeat('a', 64), self::PEPPER));
    }

    public function testDistinctIssuancesProduceDistinctKeys(): void
    {
        $first = LicenseKey::issue(self::PEPPER);
        $second = LicenseKey::issue(self::PEPPER);

        expectFalse(hash_equals($first->plaintext, $second->plaintext));
    }
}
