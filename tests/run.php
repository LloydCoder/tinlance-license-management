<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$tests = [
    new Tlms\Tests\Unit\Domain\LicenseKeyTest(),
];

$failures = 0;
$assertions = 0;

foreach ($tests as $test) {
    foreach (get_class_methods($test) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }

        try {
            $test->{$method}();
            fwrite(STDOUT, "PASS " . $test::class . "::{$method}\n");
            $assertions++;
        } catch (Throwable $exception) {
            fwrite(STDERR, "FAIL " . $test::class . "::{$method}: {$exception->getMessage()}\n");
            $failures++;
        }
    }
}

fwrite(STDOUT, sprintf("\n%d passed; %d failed\n", $assertions, $failures));
exit($failures === 0 ? 0 : 1);
