<?php

declare(strict_types=1);

namespace Tlms\Tests\Unit\Domain;

use InvalidArgumentException;
use Tlms\Domain\Licensing\DomainName;

final class DomainNameTest
{
    public function testNormalizesCaseAndOneTerminalDot(): void
    {
        expectSame('example.com', DomainName::normalize('EXAMPLE.COM.'));
    }

    public function testConvertsUnicodeDomainToIdnaAscii(): void
    {
        expectSame('xn--bcher-kva.example', DomainName::normalize('bücher.example'));
    }

    public function testRejectsSchemeAndPath(): void
    {
        expectThrows(static fn () => DomainName::normalize('https://example.com/path'), InvalidArgumentException::class);
    }

    public function testRejectsExplicitPort(): void
    {
        expectThrows(static fn () => DomainName::normalize('example.com:8443'), InvalidArgumentException::class);
    }

    public function testRejectsCredentialsAndQuery(): void
    {
        expectThrows(static fn () => DomainName::normalize('user@example.com?next=/'), InvalidArgumentException::class);
    }

    public function testRejectsWildcardByDefault(): void
    {
        expectThrows(static fn () => DomainName::normalize('*.example.com'), InvalidArgumentException::class);
    }

    public function testRejectsWhitespaceAndEmptyInput(): void
    {
        expectThrows(static fn () => DomainName::normalize(' example.com'), InvalidArgumentException::class);
        expectThrows(static fn () => DomainName::normalize(''), InvalidArgumentException::class);
    }

    public function testRejectsIpAddressLiterals(): void
    {
        expectThrows(static fn () => DomainName::normalize('192.0.2.10'), InvalidArgumentException::class);
    }

    public function testRejectsMultipleTerminalDots(): void
    {
        expectThrows(static fn () => DomainName::normalize('example.com..'), InvalidArgumentException::class);
    }

    public function testRejectsPercentEncodedInput(): void
    {
        expectThrows(static fn () => DomainName::normalize('example%2ecom'), InvalidArgumentException::class);
    }

    public function testRejectsInvalidHostnameLabels(): void
    {
        expectThrows(static fn () => DomainName::normalize('bad_label.example'), InvalidArgumentException::class);
        expectThrows(static fn () => DomainName::normalize('-bad.example'), InvalidArgumentException::class);
    }
}
