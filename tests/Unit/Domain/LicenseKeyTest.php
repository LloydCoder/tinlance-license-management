<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use Tlms\Domain\Licensing\LicenseKey;

final class LicenseKeyTest
{
    private const PEPPER = 'test-only-pepper-material-at-least-32-bytes-long';
    private const PEPPER_KEY_ID = 'licence-pepper-2026-01';

    public function testIssueProducesAHighEntropyPrefixedKeyAndVerifier(): void
    {
        $issued = LicenseKey::issue(self::PEPPER, self::PEPPER_KEY_ID);

        expectTrue(str_starts_with($issued->plaintext, 'tlms_'));
        expectSame(43, strlen(substr($issued->plaintext, strlen('tlms_'))));
        expectSame(64, strlen($issued->verifier));
        expectFalse(str_contains($issued->verifier, $issued->plaintext));
    }

    public function testIssuedKeyCanBeVerified(): void
    {
        $issued = LicenseKey::issue(self::PEPPER, self::PEPPER_KEY_ID);

        expectTrue(LicenseKey::verify($issued->plaintext, $issued->verifier, self::PEPPER, self::PEPPER_KEY_ID));
    }

    public function testTamperedKeyIsRejected(): void
    {
        $issued = LicenseKey::issue(self::PEPPER);
        $tampered = $issued->plaintext . 'x';

        expectFalse(LicenseKey::verify($tampered, $issued->verifier, self::PEPPER, self::PEPPER_KEY_ID));
    }

    public function testWrongPepperIsRejected(): void
    {
        $issued = LicenseKey::issue(self::PEPPER);

        expectFalse(LicenseKey::verify($issued->plaintext, $issued->verifier, 'a-different-pepper-material-that-is-long-enough', self::PEPPER_KEY_ID));
    }

    public function testWeakPepperIsRejectedWhenIssuing(): void
    {
        expectThrows(
            static fn () => LicenseKey::issue('too-short', self::PEPPER_KEY_ID),
            InvalidArgumentException::class,
        );
    }

    public function testMalformedKeyIsRejectedWithoutThrowing(): void
    {
        expectFalse(LicenseKey::verify('not-a-tlms-key', str_repeat('a', 64), self::PEPPER, self::PEPPER_KEY_ID));
    }

    public function testPepperKeyIdIsIncludedInTheVerifierDomain(): void
    {
        $issued = LicenseKey::issue(self::PEPPER, self::PEPPER_KEY_ID);

        expectFalse(LicenseKey::verify($issued->plaintext, $issued->verifier, self::PEPPER, 'licence-pepper-2026-02'));
    }

    public function testWeakPepperKeyIdIsRejected(): void
    {
        expectThrows(
            static fn () => LicenseKey::issue(self::PEPPER, 'bad key id'),
            InvalidArgumentException::class,
        );
    }

    public function testDistinctIssuancesProduceDistinctKeys(): void
    {
        $first = LicenseKey::issue(self::PEPPER);
        $second = LicenseKey::issue(self::PEPPER);

        expectFalse(hash_equals($first->plaintext, $second->plaintext));
    }
}
