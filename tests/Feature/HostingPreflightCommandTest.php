<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class HostingPreflightCommandTest extends TestCase
{
    public function test_preflight_emits_a_machine_readable_report_without_secrets(): void
    {
        $exitCode = Artisan::call('tlms:hosting-preflight', ['--json' => true]);
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode, 'The SQLite-only test runtime must not be qualified as a production hosting environment.');
        $this->assertFalse($report['ready']);
        $this->assertArrayHasKey('checks', $report);
        $this->assertArrayHasKey('database.vendor', $report['checks']);
        $this->assertArrayHasKey('runtime.argon2id', $report['checks']);
        $this->assertArrayHasKey('runtime.required_extensions', $report['checks']);
        $this->assertArrayHasKey('security.debug_disabled', $report['checks']);
        $this->assertArrayHasKey('security.secure_session_cookie', $report['checks']);
        $this->assertArrayHasKey('filesystem.storage_writable', $report['checks']);
        $this->assertArrayHasKey('filesystem.bootstrap_cache_writable', $report['checks']);
        $this->assertArrayHasKey('filesystem.document_root_isolated', $report['checks']);
        $this->assertStringNotContainsString((string) config('app.key'), $output);

        $databasePassword = (string) config('database.connections.mysql.password', '');

        if ($databasePassword !== '') {
            $this->assertStringNotContainsString($databasePassword, $output);
        }
    }

    public function test_preflight_json_report_has_stable_check_shape(): void
    {
        Artisan::call('tlms:hosting-preflight', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($report['checks'] as $check) {
            $this->assertSame(['passed', 'required', 'message'], array_keys($check));
            $this->assertIsBool($check['passed']);
            $this->assertIsBool($check['required']);
            $this->assertIsString($check['message']);
            $this->assertNotSame('', $check['message']);
        }
    }
    public function test_database_vendor_check_requires_a_qualified_live_database_server(): void
    {
        $report = app(\\App\\Services\\Hosting\\HostingPreflight::class)->report();

        if (config('database.default') !== 'mysql') {
            $this->assertFalse($report['checks']['database.vendor']['passed']);

            return;
        }

        $this->assertTrue($report['checks']['database.vendor']['passed']);
    }

}
