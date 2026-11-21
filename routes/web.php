<?php

use App\Http\Controllers\Setup\SuperAdminBootstrapController;
use App\Http\Middleware\RequireSecureSetupTransport;
use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => response()->json(['service' => 'TLMS', 'status' => 'development']));

Route::middleware(RequireSecureSetupTransport::class)->group(function (): void {
    Route::get('/setup/super-admin', [SuperAdminBootstrapController::class, 'show'])
        ->middleware('throttle:10,1')
        ->name('setup.super-admin');

    Route::post('/setup/super-admin', [SuperAdminBootstrapController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('setup.super-admin.store');

    Route::get('/setup/super-admin/complete', [SuperAdminBootstrapController::class, 'complete'])
        ->name('setup.super-admin.complete');
});
