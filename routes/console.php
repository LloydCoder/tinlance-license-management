<?php

use App\Services\Authorization\PlatformAccessAuditChainVerifier;
use App\Services\Hosting\HostingPreflight;
use App\Services\Licensing\LicenseExpiryProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('tlms:about', function (): void {
    $this->line('Tinlance License Management System');
})->purpose('Display the TLMS application identity');

Artisan::command('tlms:hosting-preflight {--json : Output the report as JSON}', function (): int {
    $report = app(HostingPreflight::class)->report();

    if ($this->option('json')) {
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    } else {
        foreach ($report['checks'] as $name => $check) {
            $status = $check['passed'] ? 'PASS' : 'FAIL';
            $this->line(sprintf('[%s] %s — %s', $status, $name, $check['message']));
        }

        $this->newLine();
        $this->line($report['ready'] ? 'Hosting preflight passed.' : 'Hosting preflight failed.');
    }

    return $report['ready'] ? Command::SUCCESS : Command::FAILURE;
})->purpose('Verify TLMS shared-hosting runtime, database, security and filesystem prerequisites');

Artisan::command('tlms:audit:verify', function (PlatformAccessAuditChainVerifier $verifier): int {
    $valid = $verifier->verify();

    if ($valid) {
        $this->info('Platform access audit hash chain is valid.');

        return Command::SUCCESS;
    }

    $this->error('Platform access audit hash chain verification failed.');

    return Command::FAILURE;
})->purpose('Verify platform access audit hash-chain integrity');

Artisan::command('tlms:licenses:expire {--limit=100 : Maximum number of licences to process (1–1000)}', function (LicenseExpiryProcessor $processor): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

    if ($limit === false || $limit < 1 || $limit > 1000) {
        $this->error('The expiry batch limit must be an integer between 1 and 1000.');

        return Command::INVALID;
    }

    $expired = $processor->expireDue($limit);
    $noun = $expired === 1 ? 'licence' : 'licences';
    $this->info(sprintf('%d %s expired.', $expired, $noun));

    return Command::SUCCESS;
})->purpose('Expire overdue licences after their configured grace deadlines');

Schedule::command('tlms:licenses:expire --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();
