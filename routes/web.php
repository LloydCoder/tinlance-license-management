<?php

use App\Http\Controllers\Admin\Security\SessionController;
use App\Http\Controllers\Admin\SuperAdminDashboardController;
use App\Http\Controllers\Security\MfaEnrollmentController;
use App\Http\Controllers\Setup\SuperAdminBootstrapController;
use App\Http\Middleware\EnsureMfaEnrolled;
use App\Http\Middleware\EnsureSessionVersionCurrent;
use App\Http\Middleware\EnsureSuperAdmin;
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

Route::middleware([
    RequireSecureSetupTransport::class,
    'auth',
    'verified',
    EnsureSessionVersionCurrent::class,
    'password.confirm',
    EnsureSuperAdmin::class,
])->group(function (): void {
    Route::get('/security/mfa', MfaEnrollmentController::class)
        ->name('security.mfa.enroll');
});

Route::middleware([
    RequireSecureSetupTransport::class,
    'auth',
    'verified',
    EnsureSessionVersionCurrent::class,
    EnsureMfaEnrolled::class,
    EnsureSuperAdmin::class,
])->group(function (): void {
    Route::get('/admin', SuperAdminDashboardController::class)
        ->name('admin.dashboard');

    Route::get('/admin/security/sessions', [SessionController::class, 'index'])
        ->name('admin.security.sessions');

    Route::post('/admin/security/sessions/revoke-all', [SessionController::class, 'revokeAll'])
        ->middleware('throttle:3,1')
        ->name('admin.security.sessions.revoke-all');
});
