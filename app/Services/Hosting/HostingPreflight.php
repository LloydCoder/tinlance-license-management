<?php

declare(strict_types=1);

namespace App\Services\Hosting;

use Illuminate\Support\Facades\DB;
use Throwable;

final class HostingPreflight
{
    /**
     * @return array{
     *     ready: bool,
     *     checks: array<string, array{passed: bool, required: bool, message: string}>
     * }
     */
    public function report(): array
    {
        $checks = [
            'runtime.php_version' => $this->check(
                version_compare(PHP_VERSION, '8.3.0', '>=') && version_compare(PHP_VERSION, '8.6.0', '<'),
                'PHP 8.3, 8.4 or 8.5 is required; other runtimes are not qualified.',
            ),
            'runtime.required_extensions' => $this->requiredExtensionsCheck(),
            'runtime.argon2id' => $this->argon2idCheck(),
            'database.vendor' => $this->databaseVendorCheck(),
            'security.production_environment' => $this->check(
                config('app.env') === 'production',
                'APP_ENV must be production.',
            ),
            'security.debug_disabled' => $this->check(
                config('app.debug') === false,
                'APP_DEBUG must be false.',
            ),
            'security.app_key_configured' => $this->appKeyCheck(),
            'security.secure_session_cookie' => $this->sessionSecurityCheck(),
            'filesystem.storage_writable' => $this->storageDirectoriesCheck(),
            'filesystem.bootstrap_cache_writable' => $this->check(
                is_dir(base_path('bootstrap/cache')) && is_writable(base_path('bootstrap/cache')),
                'bootstrap/cache must exist and be writable by the PHP runtime.',
            ),
            'filesystem.document_root_isolated' => $this->documentRootCheck(),
        ];

        $ready = true;

        foreach ($checks as $check) {
            if ($check['required'] && ! $check['passed']) {
                $ready = false;

                break;
            }
        }

        return [
            'ready' => $ready,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function requiredExtensionsCheck(): array
    {
        $required = [
            'ctype',
            'curl',
            'dom',
            'fileinfo',
            'filter',
            'hash',
            'intl',
            'mbstring',
            'openssl',
            'pdo',
            'pdo_mysql',
            'session',
            'sodium',
            'tokenizer',
            'xml',
        ];

        $missing = array_values(array_filter(
            $required,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));

        return $this->check(
            $missing === [],
            $missing === []
                ? 'All required PHP extensions are loaded.'
                : 'Missing required PHP extensions: '.implode(', ', $missing).'.',
        );
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function storageDirectoriesCheck(): array
    {
        $requiredDirectories = [
            storage_path('app'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        $unavailable = array_values(array_filter(
            $requiredDirectories,
            static fn (string $directory): bool => ! is_dir($directory) || ! is_writable($directory),
        ));

        return $this->check(
            $unavailable === [],
            $unavailable === []
                ? 'All required Laravel storage directories exist and are writable.'
                : 'Required Laravel storage directories are missing or not writable: '.implode(', ', $unavailable).'.',
        );
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function argon2idCheck(): array
    {
        $available = in_array('argon2id', password_algos(), true);
        $configured = config('hashing.driver') === 'argon2id';
        $memory = (int) config('hashing.argon.memory', 0);
        $time = (int) config('hashing.argon.time', 0);
        $threads = (int) config('hashing.argon.threads', 0);

        $passed = $available && $configured && $memory >= 19456 && $time >= 2 && $threads >= 1;

        return $this->check(
            $passed,
            'Argon2id must be available and configured with at least 19 MiB memory, 2 iterations and 1 thread.',
        );
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function databaseVendorCheck(): array
    {
        try {
            if (DB::connection()->getDriverName() !== 'mysql') {
                return $this->check(
                    false,
                    'The production database must be MySQL 8.4 or MariaDB 10.11; SQLite is for tests only.',
                );
            }

            $version = (string) DB::scalar('SELECT VERSION()');
            $isMariaDb = str_contains(strtolower($version), 'mariadb');
            $expectedVendor = strtolower(trim((string) config('tlms.expected_db_vendor', 'mysql')));

            $isSupportedVersion = $isMariaDb
                ? preg_match('/\b10\.11\.\d+\b/', $version) === 1
                : preg_match('/\b8\.4\.\d+\b/', $version) === 1;

            $actualVendor = $isMariaDb ? 'mariadb' : 'mysql';
            $passed = $isSupportedVersion && $actualVendor === $expectedVendor;

            return $this->check(
                $passed,
                $passed
                    ? 'Connected to the configured supported database vendor and version.'
                    : 'Database must match the configured vendor and a qualified version: MySQL 8.4 or MariaDB 10.11.',
            );
        } catch (Throwable) {
            return $this->check(
                false,
                'Database connection or version verification failed; check credentials, grants and server availability.',
            );
        }
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function appKeyCheck(): array
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            return $this->check(false, 'APP_KEY must be configured with a valid 32-byte encryption key.');
        }

        $material = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;

        return $this->check(
            config('app.cipher') === 'AES-256-CBC' && is_string($material) && strlen($material) === 32,
            'APP_KEY must resolve to exactly 32 bytes and use the configured AES-256-CBC application cipher.',
        );
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function sessionSecurityCheck(): array
    {
        $passed = config('session.secure') === true
            && config('session.encrypt') === true
            && config('session.http_only') === true
            && in_array(config('session.same_site'), ['lax', 'strict'], true);

        return $this->check(
            $passed,
            'Session cookies must be Secure, encrypted, HttpOnly and use SameSite=Lax or Strict.',
        );
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function documentRootCheck(): array
    {
        $serverDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $documentRoot = is_string($serverDocumentRoot) && trim($serverDocumentRoot) !== ''
            ? $serverDocumentRoot
            : config('tlms.expected_document_root');

        if (! is_string($documentRoot) || trim($documentRoot) === '') {
            return $this->check(
                false,
                'Document root could not be verified. Run through the web server or set TLMS_EXPECTED_DOCUMENT_ROOT for CLI preflight.',
            );
        }

        $resolvedDocumentRoot = realpath($documentRoot);
        $resolvedPublicPath = realpath(public_path());
        $resolvedBasePath = realpath(base_path());

        if ($resolvedDocumentRoot === false || $resolvedPublicPath === false || $resolvedBasePath === false) {
            return $this->check(false, 'Document root and Laravel application paths must resolve to existing directories.');
        }

        $documentRoot = $this->normalizePath($resolvedDocumentRoot);
        $publicPath = $this->normalizePath($resolvedPublicPath);
        $basePath = $this->normalizePath($resolvedBasePath);

        $applicationRootIsOutsideDocumentRoot = $basePath !== $documentRoot
            && ! str_starts_with($basePath, $documentRoot.'/');

        return $this->check(
            $documentRoot === $publicPath && $applicationRootIsOutsideDocumentRoot,
            'The web document root must resolve exactly to Laravel public/; application source and environment files must remain outside it.',
        );
    }

    /**
     * @return array{passed: bool, required: bool, message: string}
     */
    private function check(bool $passed, string $message): array
    {
        return [
            'passed' => $passed,
            'required' => true,
            'message' => $message,
        ];
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
