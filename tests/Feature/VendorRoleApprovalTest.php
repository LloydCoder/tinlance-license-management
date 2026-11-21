<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalDecision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        \Illuminate\Support\Facades\DB::table('approval_requests')
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

        \Illuminate\Support\Facades\DB::table('approval_requests')
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

    /** @return \\Illuminate\\Testing\\TestResponse<\\Illuminate\\Http\\JsonResponse> */
    private function createApprovalRequest(
        User $maker,
        VendorAccount $vendor,
        VendorMembership $target,
        Role $role,
        string $idempotencyKey,
    ): \\Illuminate\\Testing\\TestResponse {
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
