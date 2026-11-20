<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Tlms\\Tests\\' => __DIR__.'/',
        'Tlms\\' => dirname(__DIR__).'/src/',
    ];

    foreach ($prefixes as $prefix => $baseDirectory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDirectory.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

function expectTrue(bool $condition, string $message = 'Expected condition to be true'): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function expectFalse(bool $condition, string $message = 'Expected condition to be false'): void
{
    expectTrue(! $condition, $message);
}

function expectSame(mixed $expected, mixed $actual, string $message = 'Values are not identical'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function expectThrows(callable $operation, string $exceptionClass): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw new RuntimeException('Expected '.$exceptionClass.', got '.$exception::class);
    }

    throw new RuntimeException('Expected exception '.$exceptionClass.' was not thrown');
}
