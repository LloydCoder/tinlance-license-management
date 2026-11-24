<?php

declare(strict_types=1);
use Tlms\Tests\Unit\Domain\DomainNameTest;
use Tlms\Tests\Unit\Domain\Ed25519EnvelopeTest;
use Tlms\Tests\Unit\Domain\LicenseIssuanceFingerprintTest;
use Tlms\Tests\Unit\Domain\LicenseKeyPepperRingTest;
use Tlms\Tests\Unit\Domain\LicenseKeyTest;
use Tlms\Tests\Unit\Domain\LicenseLifecycleTest;
use Tlms\Tests\Unit\Domain\LicenseResponseCipherRingTest;
use Tlms\Tests\Unit\Domain\SigningTrustMetadataTest;

require __DIR__.'/bootstrap.php';

$tests = [
    new LicenseKeyTest,
    new LicenseKeyPepperRingTest,
    new DomainNameTest,
    new LicenseLifecycleTest,
    new LicenseIssuanceFingerprintTest,
    new LicenseResponseCipherRingTest,
    new Ed25519EnvelopeTest,
    new SigningTrustMetadataTest,
];

$failures = 0;
$passed = 0;

foreach ($tests as $test) {
    foreach (get_class_methods($test) as $method) {
        if (! str_starts_with($method, 'test')) {
            continue;
        }

        try {
            $test->{$method}();
            fwrite(STDOUT, 'PASS '.$test::class."::{$method}\n");
            $passed++;
        } catch (Throwable $exception) {
            fwrite(STDERR, 'FAIL '.$test::class."::{$method}: {$exception->getMessage()}\n");
            $failures++;
        }
    }
}

fwrite(STDOUT, sprintf("\n%d passed; %d failed\n", $passed, $failures));
exit($failures === 0 ? 0 : 1);
