<?php

use App\Services\Hosting\HostingPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

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
