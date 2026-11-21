<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MembershipRoleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_admin_can_assign_a_scoped_role_and_the_change_is_audited(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->authenticatedJson($actor)
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'Approved access for the assigned engineer.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.action', 'assigned')
            ->assertJsonPath('data.changed', true)
            ->assertJsonStructure(['request_id', 'data' => ['membership_id', 'role_id', 'action', 'changed']]);

        $this->assertTrue($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => 'assigned',
            'reason' => 'Approved access for the assigned engineer.',
        ]);
    }

    public function test_vendor_admin_can_revoke_a_scoped_role_and_the_change_is_audited(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');
        $target->roles()->attach($role);

        $this->authenticatedJson($actor)
            ->postJson($this->vendorRolePath($vendor, $target, $role).'/revoke', [
                'reason' => 'Engineer access is no longer required.',
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'revoked')
            ->assertJsonPath('data.changed', true);

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => 'revoked',
            'reason' => 'Engineer access is no longer required.',
        ]);
    }

    public function test_customer_owner_can_assign_a_role_only_within_its_organization(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $target = $this->customerMembership($organization, $this->createUser());
        $role = $this->role('customer', 'customer_technical_admin');

        $this->authenticatedJson($actor)
            ->postJson($this->customerRolePath($organization, $target, $role), [
                'reason' => 'Approved technical administrator appointment.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.action', 'assigned')
            ->assertJsonPath('data.changed', true);

        $this->assertTrue($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('customer_membership_role_events', [
            'customer_membership_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => 'assigned',
        ]);
    }

    public function test_customer_owner_can_revoke_a_role_and_the_change_is_audited(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $target = $this->customerMembership($organization, $this->createUser());
        $role = $this->role('customer', 'customer_technical_admin');
        $target->roles()->attach($role);

        $this->authenticatedJson($actor)
            ->postJson($this->customerRolePath($organization, $target, $role).'/revoke', [
                'reason' => 'Customer administrator access removed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'revoked')
            ->assertJsonPath('data.changed', true);

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('customer_membership_role_events', [
            'customer_membership_id' => $target->getKey(),
            'role_id' => $role->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => 'revoked',
        ]);
    }

    public function test_vendor_route_cannot_be_used_with_a_membership_from_another_vendor(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $otherVendor = $this->createVendor();
        $target = $this->vendorMembership($otherVendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->authenticatedJson($actor)
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'Cross-vendor attempt.',
            ])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_customer_route_cannot_be_used_with_a_membership_from_another_organization(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $otherOrganization = $this->createOrganization($organization->vendorAccount()->firstOrFail());
        $target = $this->customerMembership($otherOrganization, $this->createUser());
        $role = $this->role('customer', 'customer_member');

        $this->authenticatedJson($actor)
            ->postJson($this->customerRolePath($organization, $target, $role), [
                'reason' => 'Cross-organization attempt.',
            ])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_vendor_without_admin_permission_cannot_assign_roles(): void
    {
        [$actor, $vendor] = $this->vendorActor('vendor_developer');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'support_agent');

        $this->authenticatedJson($actor)
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'Unauthorized attempt.',
            ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_customer_member_cannot_assign_roles(): void
    {
        [$actor, $organization] = $this->customerActor('customer_member');
        $target = $this->customerMembership($organization, $this->createUser());
        $role = $this->role('customer', 'customer_technical_admin');

        $this->authenticatedJson($actor)
            ->postJson($this->customerRolePath($organization, $target, $role), [
                'reason' => 'Unauthorized attempt.',
            ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_wrong_scope_role_is_rejected_without_mutation(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $customerRole = $this->role('customer', 'customer_member');

        $this->authenticatedJson($actor)
            ->postJson($this->vendorRolePath($vendor, $target, $customerRole), [
                'reason' => 'Wrong scope role.',
            ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertFalse($target->refresh()->roles()->whereKey($customerRole->getKey())->exists());
    }

    public function test_role_change_requires_a_reason(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->authenticatedJson($actor)
            ->postJson($this->vendorRolePath($vendor, $target, $role), ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_unauthenticated_actor_cannot_assign_membership_roles(): void
    {
        $vendor = $this->createVendor();
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->postJson($this->vendorRolePath($vendor, $target, $role), [
            'reason' => 'No actor session.',
        ])->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_actor_without_an_enrolled_mfa_factor_is_denied_json_access(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $actor->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->actingAs($actor)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => time(),
            ])
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'MFA must be required.',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'MFA_REQUIRED');
    }

    public function test_vendor_admin_can_assign_a_role_to_an_invited_member(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser(), 'invited');
        $role = $this->role('vendor', 'support_agent');

        $this->authenticatedJson($actor)
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'Pre-provision approved invite permissions.',
            ])
            ->assertCreated();

        $this->assertTrue($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_customer_owner_can_assign_a_role_to_an_invited_member(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $target = $this->customerMembership($organization, $this->createUser(), 'invited');
        $role = $this->role('customer', 'customer_member');

        $this->authenticatedJson($actor)
            ->postJson($this->customerRolePath($organization, $target, $role), [
                'reason' => 'Pre-provision approved customer invite permissions.',
            ])
            ->assertCreated();

        $this->assertTrue($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_repeated_assignment_is_idempotent_and_does_not_duplicate_audit_events(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');
        $path = $this->vendorRolePath($vendor, $target, $role);

        $this->authenticatedJson($actor)
            ->postJson($path, ['reason' => 'Initial approved role assignment.'])
            ->assertCreated()
            ->assertJsonPath('data.changed', true);

        $this->authenticatedJson($actor)
            ->postJson($path, ['reason' => 'Retry of the same role assignment.'])
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $this->assertSame(1, $target->refresh()->roleEvents()->count());
    }

    public function test_stale_session_version_is_rejected_as_json_before_role_mutation(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $actor->forceFill(['session_version' => 2])->save();
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->actingAs($actor)
            ->withSession([
                'auth_session_version' => 1,
                'auth.password_confirmed_at' => time(),
            ])
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'Expired session must not mutate roles.',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_EXPIRED');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
        $this->assertGuest();
    }

    public function test_role_mutation_requires_recent_password_confirmation(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->actingAs($actor)
            ->withSession(['auth_session_version' => $actor->session_version])
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'Require password reauthentication.',
            ])
            ->assertStatus(423)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_expired_password_confirmation_cannot_authorize_role_mutation(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'vendor_developer');

        $this->actingAs($actor)
            ->withSession([
                'auth_session_version' => $actor->session_version,
                'auth.password_confirmed_at' => time() - 36000,
            ])
            ->postJson($this->vendorRolePath($vendor, $target, $role), [
                'reason' => 'Expired password confirmation.',
            ])
            ->assertStatus(423)
            ->assertJsonPath('code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->assertFalse($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    private function authenticatedJson(User $user): static
    {
        $sessionVersion = (int) User::query()->whereKey($user->getKey())->value('session_version');

        return $this->actingAs($user, 'web')->withSession([
            'auth_session_version' => $sessionVersion,
            'auth.password_confirmed_at' => time(),
        ]);
    }

    private function createUser(): User
    {
        $user = User::query()->create([
            'name' => 'Membership API Actor',
            'email' => 'membership-api-'.str()->random(12).'@example.test',
            'password' => str()->random(32),
            'status' => 'active',
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    /** @return array{User, VendorAccount} */
    private function vendorActor(string $roleSlug): array
    {
        $actor = $this->createUser();
        $vendor = $this->createVendor();
        $membership = $this->vendorMembership($vendor, $actor);
        $membership->roles()->attach($this->role('vendor', $roleSlug));

        return [$actor, $vendor];
    }

    /** @return array{User, CustomerOrganization} */
    private function customerActor(string $roleSlug): array
    {
        $actor = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor);
        $membership = $this->customerMembership($organization, $actor);
        $membership->roles()->attach($this->role('customer', $roleSlug));

        return [$actor, $organization];
    }

    private function createVendor(): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => 'vendor-'.str()->random(12),
            'legal_name' => 'Membership API Vendor Limited',
            'display_name' => 'Membership API Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor): CustomerOrganization
    {
        return CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'customer-'.str()->random(12),
            'legal_name' => 'Membership API Customer Limited',
            'display_name' => 'Membership API Customer',
            'status' => 'active',
        ]);
    }

    private function vendorMembership(
        VendorAccount $vendor,
        User $user,
        string $status = 'active',
    ): VendorMembership {
        return VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);
    }

    private function customerMembership(
        CustomerOrganization $organization,
        User $user,
        string $status = 'active',
    ): CustomerMembership {
        return CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);
    }

    private function role(string $scope, string $slug): Role
    {
        return Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
    }

    private function vendorRolePath(VendorAccount $vendor, VendorMembership $membership, Role $role): string
    {
        return '/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/roles/'.$role->getKey();
    }

    private function customerRolePath(
        CustomerOrganization $organization,
        CustomerMembership $membership,
        Role $role,
    ): string {
        return '/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$membership->getKey().'/roles/'.$role->getKey();
    }
}
