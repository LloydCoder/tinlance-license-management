<?php

use App\Http\Controllers\Admin\CustomerOrganizationController;
use App\Http\Controllers\Admin\Security\SessionController;
use App\Http\Controllers\Admin\SuperAdminDashboardController;
use App\Http\Controllers\Catalog\PlanCatalogueController;
use App\Http\Controllers\Catalog\ProductAssignmentController;
use App\Http\Controllers\Catalog\ProductCatalogController;
use App\Http\Controllers\Catalog\ProductVersionController;
use App\Http\Controllers\Catalog\ReleaseChannelController;
use App\Http\Controllers\Identity\CustomerOrganizationProfileController;
use App\Http\Controllers\Identity\MembershipInvitationController;
use App\Http\Controllers\Identity\MembershipLifecycleController;
use App\Http\Controllers\Identity\MembershipRoleController;
use App\Http\Controllers\Identity\VendorAccountProfileController;
use App\Http\Controllers\Identity\VendorRoleApprovalController;
use App\Http\Controllers\Identity\WorkspaceController;
use App\Http\Controllers\Licensing\LicenseAllocationController;
use App\Http\Controllers\Licensing\LicenseController;
use App\Http\Controllers\Licensing\LicenseLifecycleController;
use App\Http\Controllers\Licensing\LicenseUsageController;
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

    Route::get('/workspace/vendor-profile', [VendorAccountProfileController::class, 'index'])
        ->name('workspace.vendor-profile.index');

    Route::patch('/workspace/vendor-profile', [VendorAccountProfileController::class, 'update'])
        ->middleware([EnsureRecentPasswordConfirmation::class, EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('workspace.vendor-profile.update');

    Route::get('/workspace/customer-profiles', [CustomerOrganizationProfileController::class, 'index'])
        ->name('workspace.customer-profiles.index');

    Route::patch('/workspace/customer-profiles/{organization}', [CustomerOrganizationProfileController::class, 'update'])
        ->middleware([EnsureRecentPasswordConfirmation::class, EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('workspace.customer-profiles.update');
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
    Route::get('/products/{product}/plans', [PlanCatalogueController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.products.plans.index');
    Route::get('/plans/{plan}', [PlanCatalogueController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('api.plans.show');
    Route::get('/plans/{plan}/versions', [PlanCatalogueController::class, 'versions'])
        ->middleware('throttle:60,1')
        ->name('api.plans.versions.index');
    Route::post('/products/{product}/plans', [PlanCatalogueController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.plans.store');
    Route::post('/plans/{plan}/versions', [PlanCatalogueController::class, 'createVersion'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.plans.versions.store');
    Route::post('/plans/{plan}/versions/{version}/publish', [PlanCatalogueController::class, 'publish'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.plans.versions.publish');
    Route::patch('/plans/{plan}/versions/{version}', [PlanCatalogueController::class, 'updateVersion'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.plans.versions.update');
    Route::get('/products', [ProductCatalogController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.products.index');
    Route::post('/products', [ProductCatalogController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.store');
    Route::get('/products/{product}', [ProductCatalogController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('api.products.show');
    Route::patch('/products/{product}', [ProductCatalogController::class, 'update'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.update');
    Route::post('/products/{product}/assignments', [ProductAssignmentController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.assignments.store');
    Route::delete('/products/{product}/assignments/{membership}', [ProductAssignmentController::class, 'destroy'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.assignments.destroy');
    Route::get('/products/{product}/versions', [ProductVersionController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.products.versions.index');
    Route::post('/products/{product}/versions', [ProductVersionController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.versions.store');
    Route::get('/products/{product}/versions/{productVersion}', [ProductVersionController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('api.products.versions.show');
    Route::patch('/products/{product}/versions/{productVersion}', [ProductVersionController::class, 'update'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.versions.update');
    Route::post('/products/{product}/versions/{productVersion}/publish', [ProductVersionController::class, 'publish'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.versions.publish');
    Route::get('/products/{product}/release-channels', [ReleaseChannelController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.products.release-channels.index');
    Route::post('/products/{product}/release-channels', [ReleaseChannelController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.release-channels.store');
    Route::post('/products/{product}/release-channels/{releaseChannel}/promote', [ReleaseChannelController::class, 'promote'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.products.release-channels.promote');
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
    Route::post('/products/{product}/licenses', [LicenseController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.products.licenses.store');
    Route::post('/license-issuance-requests/{issuanceRequest}/secret', [LicenseController::class, 'revealSecret'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:3,1'])
        ->name('api.license-issuance-requests.secret');
    Route::post('/licenses/{license}/transitions', [LicenseLifecycleController::class, 'transition'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.licenses.transitions.store');
    Route::post('/licenses/{license}/renewals', [LicenseLifecycleController::class, 'renew'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:5,1'])
        ->name('api.licenses.renewals.store');
    Route::post('/licenses/{license}/allocations', [LicenseAllocationController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.licenses.allocations.store');
    Route::delete('/licenses/{license}/allocations/{allocation}', [LicenseAllocationController::class, 'destroy'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('api.licenses.allocations.destroy');
    Route::post('/licenses/{license}/usage', [LicenseUsageController::class, 'store'])
        ->middleware([EnsureRecentMfaStepUp::class, 'throttle:30,1'])
        ->name('api.licenses.usage.store');
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

    Route::get('/admin/customers', [CustomerOrganizationController::class, 'index'])
        ->name('admin.customers.index');

    Route::post('/admin/customers', [CustomerOrganizationController::class, 'store'])
        ->middleware([EnsureRecentPasswordConfirmation::class, EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('admin.customers.store');

    Route::patch('/admin/customers/{organization}', [CustomerOrganizationController::class, 'update'])
        ->middleware([EnsureRecentPasswordConfirmation::class, EnsureRecentMfaStepUp::class, 'throttle:10,1'])
        ->name('admin.customers.update');

    Route::get('/admin/security/sessions', [SessionController::class, 'index'])
        ->middleware('password.confirm')
        ->name('admin.security.sessions');

    Route::post('/admin/security/sessions/revoke-all', [SessionController::class, 'revokeAll'])
        ->middleware(['password.confirm', EnsureRecentMfaStepUp::class, 'throttle:3,1'])
        ->name('admin.security.sessions.revoke-all');
});
