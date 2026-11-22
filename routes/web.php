<?php

use App\Http\Controllers\Admin\Security\SessionController;
use App\Http\Controllers\Admin\SuperAdminDashboardController;
use App\Http\Controllers\Identity\MembershipInvitationController;
use App\Http\Controllers\Identity\MembershipLifecycleController;
use App\Http\Controllers\Identity\MembershipRoleController;
use App\Http\Controllers\Identity\VendorRoleApprovalController;
use App\Http\Controllers\Identity\WorkspaceController;
use App\Http\Controllers\Security\MfaEnrollmentController;
use App\Http\Controllers\Security\MfaStepUpController;
use App\Http\Controllers\Setup\SuperAdminBootstrapController;
use App\Http\Middleware\EnsureMfaEnrolled;
use App\Http\Middleware\EnsureRecentMfaStepUp;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
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

Route::middleware(RequireSecureSetupTransport::class)->group(function (): void {
    Route::get('/membership-invitations/accept', [MembershipInvitationController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('membership-invitations.accept.form');
    Route::post('/membership-invitations/prepare', [MembershipInvitationController::class, 'prepare'])
        ->middleware('throttle:10,1')
        ->name('membership-invitations.prepare');
    Route::post('/membership-invitations/accept', [MembershipInvitationController::class, 'acceptNewAccount'])
        ->middleware('throttle:5,1')
        ->name('membership-invitations.accept.new');
    Route::post('/membership-invitations/accept/authenticated', [MembershipInvitationController::class, 'acceptAuthenticated'])
        ->middleware([
            'auth',
            'verified',
            EnsureSessionVersionCurrent::class,
            'throttle:5,1',
        ])
        ->name('membership-invitations.accept.authenticated');
    Route::get('/membership-invitations/accepted', [MembershipInvitationController::class, 'accepted'])
        ->name('membership-invitations.accepted');
});

Route::middleware([
    RequireSecureSetupTransport::class,
    'auth',
    'verified',
    EnsureSessionVersionCurrent::class,
    EnsureMfaEnrolled::class,
])->group(function (): void {
    Route::get('/workspace', WorkspaceController::class)
        ->name('workspace.home');
});

Route::middleware([
    RequireSecureSetupTransport::class,
    'auth',
    'verified',
    EnsureSessionVersionCurrent::class,
    'password.confirm',
])->group(function (): void {
    // Every authenticated tenant user must be able to enroll their own MFA
    // before accessing protected vendor/customer operations.
    Route::get('/security/mfa', MfaEnrollmentController::class)
        ->name('security.mfa.enroll');
});

Route::middleware([
    RequireSecureSetupTransport::class,
    'auth',
    'verified',
    EnsureSessionVersionCurrent::class,
    EnsureRecentPasswordConfirmation::class,
    EnsureMfaEnrolled::class,
])->prefix('/api/v1')->group(function (): void {
    Route::post('/vendor-accounts/{vendor}/memberships/{membership}/role-approval-requests', [VendorRoleApprovalController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.vendor.role-approvals.store');
    Route::get('/vendor-accounts/{vendor}/role-approval-requests', [VendorRoleApprovalController::class, 'index'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:30,1'])
        ->name('api.vendor.role-approvals.index');
    Route::get('/vendor-accounts/{vendor}/role-approval-requests/{approvalRequest}', [VendorRoleApprovalController::class, 'show'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:30,1'])
        ->name('api.vendor.role-approvals.show');
    Route::post('/vendor-accounts/{vendor}/role-approval-requests/{approvalRequest}/approve', [VendorRoleApprovalController::class, 'approve'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.vendor.role-approvals.approve');
    Route::post('/vendor-accounts/{vendor}/role-approval-requests/{approvalRequest}/reject', [VendorRoleApprovalController::class, 'reject'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.vendor.role-approvals.reject');

    Route::post('/security/step-up/totp', [MfaStepUpController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('api.security.step-up.totp');
    Route::post('/vendor-accounts/{vendor}/memberships/invitations', [MembershipInvitationController::class, 'storeVendor'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.vendor-memberships.invitations.store');
    Route::post('/vendor-accounts/{vendor}/memberships/{membership}/invitation/resend', [MembershipInvitationController::class, 'resendVendor'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.vendor-memberships.invitations.resend');

    Route::post('/vendor-accounts/{vendor}/memberships/{membership}/reinstate', [MembershipInvitationController::class, 'reinstateVendor'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.vendor-memberships.reinstate');

    Route::post('/customer-organizations/{organization}/memberships/invitations', [MembershipInvitationController::class, 'storeCustomer'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.customer-memberships.invitations.store');
    Route::post('/customer-organizations/{organization}/memberships/{membership}/invitation/resend', [MembershipInvitationController::class, 'resendCustomer'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.customer-memberships.invitations.resend');

    Route::post('/customer-organizations/{organization}/memberships/{membership}/reinstate', [MembershipInvitationController::class, 'reinstateCustomer'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.customer-memberships.reinstate');

    Route::post('/vendor-accounts/{vendor}/memberships/{membership}/revoke', [MembershipLifecycleController::class, 'revokeVendor'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.vendor-memberships.revoke');

    Route::post('/customer-organizations/{organization}/memberships/{membership}/revoke', [MembershipLifecycleController::class, 'revokeCustomer'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.customer-memberships.revoke');

    Route::post('/vendor-accounts/{vendor}/memberships/{membership}/roles/{role}', [MembershipRoleController::class, 'assignVendor'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.vendor-memberships.roles.assign');
    Route::post('/vendor-accounts/{vendor}/memberships/{membership}/roles/{role}/revoke', [MembershipRoleController::class, 'revokeVendor'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.vendor-memberships.roles.revoke');

    Route::post('/customer-organizations/{organization}/memberships/{membership}/roles/{role}', [MembershipRoleController::class, 'assignCustomer'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.customer-memberships.roles.assign');
    Route::post('/customer-organizations/{organization}/memberships/{membership}/roles/{role}/revoke', [MembershipRoleController::class, 'revokeCustomer'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.customer-memberships.roles.revoke');
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
        ->middleware('password.confirm')
        ->name('admin.security.sessions');

    Route::post('/admin/security/sessions/revoke-all', [SessionController::class, 'revokeAll'])
        ->middleware(['password.confirm', 'throttle:3,1'])
        ->name('admin.security.sessions.revoke-all');
});
