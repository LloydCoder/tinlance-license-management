<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipRoleAssignmentService;
use App\Services\Authorization\VendorRoleApprovalService;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class VendorRoleApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_high_risk_vendor_role_grant_creates_pending_request_without_mutating_membership(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $financeRole = $this->role('finance_admin');

        $response = $this->createApprovalRequest($maker, $vendor, $target, $financeRole, 'maker-key-000001');

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.role_slug', 'finance_admin');

        $this->assertFalse($target->refresh()->roles()->whereKey($financeRole->getKey())->exists());
        $this->assertDatabaseHas('approval_requests', [
            'vendor_account_id' => $vendor->getKey(),
            'target_vendor_membership_id' => $target->getKey(),
            'role_id' => $financeRole->getKey(),
            'status' => 'pending',
            'requested_by_user_id' => $maker->getKey(),
        ]);
    }

    public function test_vendor_admin_grant_requires_maker_checker_after_the_first_admin_bootstrap(): void
    {
        $vendor = $this->createVendor();
        $superAdmin = $this->createActor($vendor);
        $firstAdmin = $this->createUser();
        $firstMembership = $this->createMembership($vendor, $firstAdmin);
        $vendorAdminRole = $this->role('vendor_admin');

        $this->assertTrue(app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $superAdmin,
            $vendor,
            $firstMembership,
            $vendorAdminRole,
            'Bootstrap the first tenant-scoped vendor administrator.',
        ));

        $secondAdmin = $this->createUser();
        $secondMembership = $this->createMembership($vendor, $secondAdmin);

        $request = $this->createApprovalRequest(
            $firstAdmin,
            $vendor,
            $secondMembership,
            $vendorAdminRole,
            'vendor-admin-maker-0001',
        );
        $request->assertAccepted()->assertJsonPath('data.role_slug', 'vendor_admin');

        $requestId = $request->json('data.id');
        $this->authenticatedJson($superAdmin)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Independent approval of the second vendor administrator.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $authorization = app(\\App\\Services\\Authorization\\MembershipAuthorization::class);
        $this->assertTrue($authorization->vendorCan($secondAdmin, $vendor, 'admins.manage'));
        $this->assertFalse($authorization->vendorCan($secondAdmin, $vendor, 'approvals.review'));
        $this->assertFalse($authorization->vendorCan($secondAdmin, $vendor, 'platform.settings.manage'));
        $this->assertFalse($authorization->vendorCan($secondAdmin, $vendor, 'payments.read'));
        $this->assertFalse($authorization->vendorCan($secondAdmin, $vendor, 'releases.publish'));
    }

    public function test_second_super_admin_can_approve_and_apply_high_risk_role_grant(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $financeRole = $this->role('finance_admin');

        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $financeRole, 'maker-key-000002')
            ->json('data.id');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Independent finance role approval.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertTrue($target->refresh()->roles()->whereKey($financeRole->getKey())->exists());
        $this->assertDatabaseHas('approval_decisions', [
            'approval_request_id' => $requestId,
            'actor_user_id' => $checker->getKey(),
            'decision' => 'approved',
        ]);
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $target->getKey(),
            'role_id' => $financeRole->getKey(),
            'actor_user_id' => $checker->getKey(),
            'action' => 'assigned',
        ]);
    }

    public function test_checker_can_list_pending_requests_within_their_vendor_scope(): void
    {
        $vendorA = $this->createVendor();
        $vendorB = $this->createVendor();
        $makerA = $this->createActor($vendorA);
        $checkerA = $this->createActor($vendorA);
        $makerB = $this->createActor($vendorB);
        $targetA = $this->createMembership($vendorA, $this->createUser());
        $targetB = $this->createMembership($vendorB, $this->createUser());

        $requestA = $this->createApprovalRequest($makerA, $vendorA, $targetA, $this->role('finance_admin'), 'maker-key-000012')->json('data.id');
        $this->createApprovalRequest($makerB, $vendorB, $targetB, $this->role('finance_admin'), 'maker-key-000013');

        $this->authenticatedJson($checkerA)
            ->getJson('/api/v1/vendor-accounts/'.$vendorA->getKey().'/role-approval-requests?status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $requestA)
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_admin_without_approval_review_sees_only_their_own_requests(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $limitedAdmin = $this->createActorWithPermission($vendor, 'admins.manage');
        $target = $this->createMembership($vendor, $this->createUser());

        $this->createApprovalRequest($maker, $vendor, $target, $this->role('finance_admin'), 'maker-key-000014');

        $this->authenticatedJson($limitedAdmin)
            ->getJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/role-approval-requests?status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_former_vendor_member_cannot_view_their_old_approval_request(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $requestId = $this->createApprovalRequest(
            $maker,
            $vendor,
            $target,
            $this->role('finance_admin'),
            'maker-key-former-0001',
        )->json('data.id');

        VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $maker->getKey())
            ->update(['status' => 'revoked']);

        $this->authenticatedJson($maker)
            ->getJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/role-approval-requests/'.$requestId)
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_maker_cannot_approve_own_role_grant_request(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $role, 'maker-key-000003')->json('data.id');

        $this->authenticatedJson($maker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Attempted self approval.',
            ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_approver_without_approvals_review_permission_is_denied(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActorWithPermission($vendor, 'admins.manage');
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $role, 'maker-key-000004')->json('data.id');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Checker lacks approval permission.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_rejected_request_never_mutates_target_membership(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('vendor_product_manager');
        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $role, 'maker-key-000005')->json('data.id');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'reject'), [
                'reason' => 'Insufficient business justification.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('approval_decisions', [
            'approval_request_id' => $requestId,
            'actor_user_id' => $checker->getKey(),
            'decision' => 'rejected',
        ]);
    }

    public function test_idempotency_key_returns_existing_request_and_rejects_payload_reuse(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $financeRole = $this->role('finance_admin');
        $productRole = $this->role('vendor_product_manager');

        $first = $this->createApprovalRequest($maker, $vendor, $target, $financeRole, 'maker-key-000006');
        $requestId = $first->json('data.id');

        $this->createApprovalRequest($maker, $vendor, $target, $financeRole, 'maker-key-000006')
            ->assertOk()
            ->assertJsonPath('data.id', $requestId);

        $this->createApprovalRequest($maker, $vendor, $target, $productRole, 'maker-key-000006')
            ->assertStatus(409)
            ->assertJsonPath('code', 'REQUEST_FAILED');
    }

    public function test_request_from_another_vendor_cannot_be_approved_through_this_vendor_path(): void
    {
        $vendorA = $this->createVendor();
        $vendorB = $this->createVendor();
        $maker = $this->createActor($vendorA);
        $checker = $this->createActor($vendorB);
        $target = $this->createMembership($vendorA, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest($maker, $vendorA, $target, $role, 'maker-key-000007')->json('data.id');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendorB, $requestId, 'approve'), [
                'reason' => 'Cross-vendor approval attempt.',
            ])
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_expired_request_transitions_to_expired_and_cannot_be_approved(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $role, 'maker-key-000008')->json('data.id');

        DB::table('approval_requests')
            ->where('id', $requestId)
            ->update(['expires_at' => now()->subSecond()]);

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Expired request.',
            ])
            ->assertStatus(410)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'APPROVAL_EXPIRED');

        $this->assertDatabaseHas('approval_requests', ['id' => $requestId, 'status' => 'expired']);
        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_high_risk_role_cannot_be_assigned_through_direct_mutation_endpoint(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');

        $this->authenticatedJson($maker)
            ->postJson(
                '/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/roles/'.$role->getKey(),
                ['reason' => 'Attempt direct high-risk role grant.'],
            )
            ->assertForbidden();

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_tampered_approval_payload_is_rejected_before_role_mutation(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $role, 'maker-key-000009')->json('data.id');

        DB::table('approval_requests')
            ->where('id', $requestId)
            ->update(['reason' => 'Tampered after maker submission.']);

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Attempt to approve altered payload.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REQUEST_FAILED');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('approval_requests', ['id' => $requestId, 'status' => 'pending']);
    }

    public function test_high_risk_role_can_be_revoked_immediately_after_approval(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $role, 'maker-key-000010')->json('data.id');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Approved for a time-bound finance assignment.',
            ])
            ->assertOk();

        $this->authenticatedJson($maker)
            ->postJson(
                '/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/roles/'.$role->getKey().'/revoke',
                ['reason' => 'Immediate privileged access revocation.'],
            )
            ->assertOk();

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'actor_user_id' => $maker->getKey(),
            'action' => 'revoked',
        ]);
    }

    public function test_approval_decision_evidence_is_immutable(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest($maker, $vendor, $target, $role, 'maker-key-000011')->json('data.id');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'Independent checker decision.',
            ])
            ->assertOk();

        $decision = ApprovalDecision::query()->where('approval_request_id', $requestId)->firstOrFail();

        $this->expectException(\LogicException::class);

        $decision->update(['reason' => 'Altered decision evidence.']);
    }

    /** @return TestResponse<JsonResponse> */
    private function createApprovalRequest(
        User $maker,
        VendorAccount $vendor,
        VendorMembership $target,
        Role $role,
        string $idempotencyKey,
    ): TestResponse {
        return $this->authenticatedJson($maker)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson(
                '/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/role-approval-requests',
                [
                    'role_id' => $role->getKey(),
                    'reason' => 'Business justification for a privileged role grant.',
                ],
            );
    }

    private function approvalPath(VendorAccount $vendor, string $requestId, string $action): string
    {
        return '/api/v1/vendor-accounts/'.$vendor->getKey().'/role-approval-requests/'.$requestId.'/'.$action;
    }

    public function test_approval_and_rejection_require_non_whitespace_decision_reasons(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest(
            $maker,
            $vendor,
            $target,
            $role,
            'maker-key-whitespace-01',
        )->json('data.id');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->authenticatedJson($checker)
            ->postJson($this->approvalPath($vendor, $requestId, 'reject'), [
                'reason' => " \t ",
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $requestId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('approval_decisions', [
            'approval_request_id' => $requestId,
        ]);

        $pendingRequest = ApprovalRequest::query()->whereKey($requestId)->firstOrFail();

        try {
            app(VendorRoleApprovalService::class)->rejectVendorRoleGrant(
                $checker,
                $vendor,
                $pendingRequest,
                '   ',
            );
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('approval_requests', [
                'id' => $requestId,
                'status' => 'pending',
            ]);

            return;
        }

        $this->fail('The approval service must reject a whitespace-only decision reason.');
    }

    public function test_pending_request_cannot_be_used_as_direct_authority_to_assign_a_high_risk_role(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $checker = $this->createActor($vendor);
        $target = $this->createMembership($vendor, $this->createUser());
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest(
            $maker,
            $vendor,
            $target,
            $role,
            'maker-key-bypass-0001',
        )->json('data.id');
        $pendingRequest = ApprovalRequest::query()->whereKey($requestId)->firstOrFail();

        try {
            app(MembershipRoleAssignmentService::class)->assignVendorRoleAfterApproval(
                $checker,
                $vendor,
                $target,
                $role,
                'Attempted direct use of pending request.',
                $pendingRequest,
            );
        } catch (AuthorizationException) {
            $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());

            return;
        }

        $this->fail('A pending request must never authorize a privileged role grant.');
    }

    public function test_role_beneficiary_cannot_reject_their_own_privileged_grant_request(): void
    {
        $vendor = $this->createVendor();
        $maker = $this->createActor($vendor);
        $beneficiary = $this->createActor($vendor);
        $target = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $beneficiary->getKey())
            ->firstOrFail();
        $role = $this->role('finance_admin');
        $requestId = $this->createApprovalRequest(
            $maker,
            $vendor,
            $target,
            $role,
            'maker-key-beneficiary-01',
        )->json('data.id');

        $this->authenticatedJson($beneficiary)
            ->postJson($this->approvalPath($vendor, $requestId, 'approve'), [
                'reason' => 'The beneficiary must not approve their own grant.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->authenticatedJson($beneficiary)
            ->postJson($this->approvalPath($vendor, $requestId, 'reject'), [
                'reason' => 'The beneficiary must not decide their own grant.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $requestId,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('approval_decisions', [
            'approval_request_id' => $requestId,
            'actor_user_id' => $beneficiary->getKey(),
        ]);
    }

    private function authenticatedJson(User $user): static
    {
        return $this->actingAs($user, 'web')->withSession([
            'auth_session_version' => $this->sessionVersion($user),
            'auth.password_confirmed_at' => time(),
            'auth.mfa_step_up_at' => time(),
            'auth.mfa_step_up_method' => 'totp',
        ]);
    }

    private function sessionVersion(User $user): int
    {
        return (int) User::query()->whereKey($user->getKey())->value('session_version');
    }

    private function createActor(VendorAccount $vendor): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($vendor, $user);
        $membership->roles()->attach($this->role('super_admin'));

        return $user;
    }

    private function createActorWithPermission(VendorAccount $vendor, string $permissionName): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($vendor, $user);
        $role = Role::query()->create([
            'scope' => 'vendor',
            'slug' => 'approval-limited-'.$user->getKey(),
            'name' => 'Approval Limited',
            'is_system' => false,
        ]);
        $permission = Permission::query()->where('scope', 'vendor')->where('name', $permissionName)->firstOrFail();
        $role->permissions()->attach($permission->getKey());
        $membership->roles()->attach($role->getKey());

        return $user;
    }

    private function createUser(): User
    {
        $user = User::query()->create([
            'name' => 'Role Approval User',
            'email' => 'role-approval-'.str()->random(12).'@example.test',
            'password' => str()->random(32),
            'status' => 'active',
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['unused-recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    private function createVendor(): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => 'role-approval-'.str()->random(12),
            'legal_name' => 'Role Approval Vendor Limited',
            'display_name' => 'Role Approval Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createMembership(VendorAccount $vendor, User $user): VendorMembership
    {
        return VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function role(string $slug): Role
    {
        return Role::query()->where('scope', 'vendor')->where('slug', $slug)->firstOrFail();
    }
}
