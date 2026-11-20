<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('tlms:about', function (): void {
    $this->line('Tinlance License Management System');
})->purpose('Display the TLMS application identity');
