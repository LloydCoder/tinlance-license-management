<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipRoleEvent;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\MembershipRoleAssignmentService;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class MembershipRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_super_admin_can_bootstrap_only_the_first_vendor_admin_with_minimal_permissions(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $firstAdmin = $this->createUser('first-vendor-admin');
        $firstMembership = $this->vendorMembership($vendor, $firstAdmin);
        $vendorAdminRole = $this->role('vendor', 'vendor_admin');

        $this->assertTrue(app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $firstMembership,
            $vendorAdminRole,
            'Bootstrap the first tenant-scoped vendor administrator.',
        ));

        $authorization = app(MembershipAuthorization::class);
        $this->assertTrue($authorization->vendorCan($firstAdmin, $vendor, 'vendor.account.read'));
        $this->assertTrue($authorization->vendorCan($firstAdmin, $vendor, 'admins.manage'));
        $this->assertTrue($authorization->vendorCan($firstAdmin, $vendor, 'audit.read'));

        foreach ([
            'platform.settings.manage',
            'customers.manage',
            'payments.read',
            'payments.refund',
            'releases.publish',
            'approvals.review',
            'signing.operational.rotate',
        ] as $forbiddenPermission) {
            $this->assertFalse($authorization->vendorCan($firstAdmin, $vendor, $forbiddenPermission));
        }

        $secondAdmin = $this->createUser('second-vendor-admin');
        $secondMembership = $this->vendorMembership($vendor, $secondAdmin);

        $this->expectException(AuthorizationException::class);
        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $secondMembership,
            $vendorAdminRole,
            'A second vendor administrator requires maker-checker approval.',
        );
    }

    public function test_super_admin_cannot_bootstrap_vendor_admin_on_an_unaccepted_invitation(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $this->createUser('invited-vendor-admin')->getKey(),
            'status' => 'invited',
            'invited_by' => $actor->getKey(),
        ]);

        $this->expectException(AuthorizationException::class);
        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $this->role('vendor', 'vendor_admin'),
            'Do not grant tenant administration before invitation acceptance.',
        );
    }

    public function test_vendor_admin_can_assign_non_privileged_role_to_another_member_and_audit_it(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('target-vendor'));
        $role = $this->role('vendor', 'vendor_developer');

        $assigned = app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $role,
            'Provision developer access for the approved engineer.',
        );

        $this->assertTrue($assigned);
        $this->assertTrue($target->refresh()->roles()->whereKey($role->id)->exists());
        $this->assertSame(1, $target->refresh()->roleEvents()->count());
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $target->id,
            'role_id' => $role->id,
            'actor_user_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'assigned',
        ]);
    }

    public function test_repeated_vendor_role_assignment_is_idempotent_and_creates_one_event(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('idempotent-target'));
        $role = $this->role('vendor', 'vendor_developer');
        $service = app(MembershipRoleAssignmentService::class);

        $this->assertTrue($service->assignVendorRole($actor, $vendor, $target, $role, 'Approved access grant.'));
        $this->assertFalse($service->assignVendorRole($actor, $vendor, $target, $role, 'Duplicate delivery.'));

        $this->assertSame(1, VendorMembershipRoleEvent::query()
            ->where('vendor_membership_id', $target->id)
            ->where('role_id', $role->id)
            ->where('action', 'assigned')
            ->count());
    }

    public function test_suspended_target_user_cannot_receive_vendor_role(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $targetUser = $this->createUser('suspended-vendor-target');
        $target = $this->vendorMembership($vendor, $targetUser);
        User::query()->whereKey($targetUser->id)->update(['status' => 'suspended']);

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $this->role('vendor', 'vendor_developer'),
            'Do not grant access to suspended accounts.',
        );
    }

    public function test_vendor_assignment_rejects_membership_from_another_vendor(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $otherVendor = $this->createVendor('secondary');
        $target = $this->vendorMembership($otherVendor, $this->createUser('other-vendor-target'));

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $this->role('vendor', 'vendor_developer'),
            'Must not cross vendor boundary.',
        );
    }

    public function test_vendor_assignment_rejects_customer_scoped_role(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('wrong-role-target'));

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $this->role('customer', 'customer_member'),
            'Wrong role scope.',
        );
    }

    public function test_generic_vendor_assignment_rejects_privileged_roles(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('privileged-target'));
        $service = app(MembershipRoleAssignmentService::class);

        foreach (['super_admin', 'platform_admin', 'finance_admin', 'vendor_product_manager', 'reseller_manager'] as $slug) {
            try {
                $service->assignVendorRole($actor, $vendor, $target, $this->role('vendor', $slug), 'Generic grant attempt.');
                $this->fail('Privileged role assignment must be denied for '.$slug.'.');
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('vendor_membership_roles', [
                    'vendor_membership_id' => $target->id,
                    'role_id' => $this->role('vendor', $slug)->id,
                ]);
            }
        }
    }

    public function test_vendor_actor_without_admin_permission_cannot_assign_roles(): void
    {
        [$actor, $vendor] = $this->vendorActor('vendor_developer');
        $target = $this->vendorMembership($vendor, $this->createUser('unauthorized-target'));

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $this->role('vendor', 'support_agent'),
            'Unauthorized grant.',
        );
    }

    public function test_vendor_actor_cannot_assign_roles_to_their_own_membership(): void
    {
        [$actor, $vendor, $membership] = $this->vendorActor('super_admin');

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $membership,
            $this->role('vendor', 'vendor_developer'),
            'Self assignment.',
        );
    }

    public function test_customer_owner_can_assign_technical_role_to_another_member_and_audit_it(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $target = $this->customerMembership($organization, $this->createUser('customer-target'));
        $role = $this->role('customer', 'customer_technical_admin');

        $assigned = app(MembershipRoleAssignmentService::class)->assignCustomerRole(
            $actor,
            $organization,
            $target,
            $role,
            'Provision technical access for the customer administrator.',
        );

        $this->assertTrue($assigned);
        $this->assertTrue($target->refresh()->roles()->whereKey($role->id)->exists());
        $this->assertSame(1, $target->refresh()->roleEvents()->count());
        $this->assertDatabaseHas('customer_membership_role_events', [
            'customer_membership_id' => $target->id,
            'role_id' => $role->id,
            'actor_user_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'assigned',
        ]);
    }

    public function test_suspended_target_user_cannot_receive_customer_role(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $targetUser = $this->createUser('suspended-customer-target');
        $target = $this->customerMembership($organization, $targetUser);
        User::query()->whereKey($targetUser->id)->update(['status' => 'suspended']);

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignCustomerRole(
            $actor,
            $organization,
            $target,
            $this->role('customer', 'customer_member'),
            'Do not grant access to suspended customer accounts.',
        );
    }

    public function test_generic_customer_assignment_cannot_transfer_customer_owner_role(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $target = $this->customerMembership($organization, $this->createUser('owner-transfer-target'));

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignCustomerRole(
            $actor,
            $organization,
            $target,
            $this->role('customer', 'customer_owner'),
            'Owner transfer requires a dedicated approval workflow.',
        );
    }

    public function test_customer_member_without_membership_management_permission_cannot_assign_roles(): void
    {
        [$actor, $organization] = $this->customerActor('customer_member');
        $target = $this->customerMembership($organization, $this->createUser('customer-member-target'));

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignCustomerRole(
            $actor,
            $organization,
            $target,
            $this->role('customer', 'customer_technical_admin'),
            'Unauthorized customer role grant.',
        );
    }

    public function test_customer_assignment_rejects_membership_from_another_organization(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $otherOrganization = $this->createOrganization($organization->vendorAccount()->firstOrFail());
        $target = $this->customerMembership($otherOrganization, $this->createUser('other-org-target'));

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignCustomerRole(
            $actor,
            $organization,
            $target,
            $this->role('customer', 'customer_member'),
            'Must not cross customer boundary.',
        );
    }

    public function test_role_assignment_requires_a_nonempty_reason(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('no-reason-target'));

        $this->expectException(AuthorizationException::class);

        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $this->role('vendor', 'vendor_developer'),
            '   ',
        );
    }

    public function test_membership_role_events_cannot_be_updated_or_deleted_through_eloquent(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('immutable-event-target'));
        $role = $this->role('vendor', 'vendor_developer');

        app(MembershipRoleAssignmentService::class)->assignVendorRole(
            $actor,
            $vendor,
            $target,
            $role,
            'Create immutable event evidence.',
        );

        $event = VendorMembershipRoleEvent::query()->firstOrFail();

        try {
            $event->update(['reason' => 'tampered']);
            $this->fail('Role assignment events must be immutable.');
        } catch (LogicException) {
            $this->assertDatabaseHas('vendor_membership_role_events', ['id' => $event->id, 'reason' => 'Create immutable event evidence.']);
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_vendor_admin_can_revoke_assignable_role_and_audit_it(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('revoke-vendor-target'));
        $role = $this->role('vendor', 'vendor_developer');
        $target->roles()->attach($role);
        $service = app(MembershipRoleAssignmentService::class);

        $this->assertTrue($service->revokeVendorRole($actor, $vendor, $target, $role, 'Remove developer access after offboarding.'));
        $this->assertFalse($target->refresh()->roles()->whereKey($role->id)->exists());
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $target->id,
            'role_id' => $role->id,
            'actor_user_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'revoked',
        ]);
    }

    public function test_customer_owner_can_revoke_assignable_customer_role_and_audit_it(): void
    {
        [$actor, $organization] = $this->customerActor('customer_owner');
        $target = $this->customerMembership($organization, $this->createUser('revoke-customer-target'));
        $role = $this->role('customer', 'customer_technical_admin');
        $target->roles()->attach($role);
        $service = app(MembershipRoleAssignmentService::class);

        $this->assertTrue($service->revokeCustomerRole(
            $actor,
            $organization,
            $target,
            $role,
            'Remove technical access after contract completion.',
        ));
        $this->assertFalse($target->refresh()->roles()->whereKey($role->id)->exists());
        $this->assertDatabaseHas('customer_membership_role_events', [
            'customer_membership_id' => $target->id,
            'role_id' => $role->id,
            'actor_user_id' => $actor->id,
            'actor_type' => 'user',
            'action' => 'revoked',
        ]);
    }

    public function test_repeated_role_revocation_is_idempotent(): void
    {
        [$actor, $vendor] = $this->vendorActor('super_admin');
        $target = $this->vendorMembership($vendor, $this->createUser('idempotent-revoke-target'));
        $role = $this->role('vendor', 'vendor_developer');
        $target->roles()->attach($role);
        $service = app(MembershipRoleAssignmentService::class);

        $this->assertTrue($service->revokeVendorRole($actor, $vendor, $target, $role, 'Approved role removal.'));
        $this->assertFalse($service->revokeVendorRole($actor, $vendor, $target, $role, 'Duplicate removal delivery.'));

        $this->assertSame(1, VendorMembershipRoleEvent::query()
            ->where('vendor_membership_id', $target->id)
            ->where('role_id', $role->id)
            ->where('action', 'revoked')
            ->count());
    }

    /** @return array{User, VendorAccount, VendorMembership} */
    private function vendorActor(string $roleSlug): array
    {
        $actor = $this->createUser('vendor-actor');
        $vendor = $this->createVendor();
        $membership = $this->vendorMembership($vendor, $actor);
        $membership->roles()->attach($this->role('vendor', $roleSlug));

        return [$actor, $vendor, $membership];
    }

    /** @return array{User, CustomerOrganization, CustomerMembership} */
    private function customerActor(string $roleSlug): array
    {
        $actor = $this->createUser('customer-actor');
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor);
        $membership = $this->customerMembership($organization, $actor);
        $membership->roles()->attach($this->role('customer', $roleSlug));

        return [$actor, $organization, $membership];
    }

    private function createUser(string $suffix): User
    {
        return User::create([
            'name' => 'Role Assignment Test',
            'email' => 'role-assignment-'.$suffix.'-'.str()->random(8).'@example.com',
            'password' => 'correct-horse-battery-staple',
            'status' => 'active',
        ]);
    }

    private function createVendor(string $key = 'primary'): VendorAccount
    {
        return VendorAccount::create([
            'installation_key' => $key,
            'legal_name' => 'Tinlance Limited',
            'display_name' => 'Tinlance',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor): CustomerOrganization
    {
        return CustomerOrganization::create([
            'vendor_account_id' => $vendor->id,
            'external_reference' => 'org-'.str()->random(12),
            'legal_name' => 'Example Customer Limited',
            'display_name' => 'Example Customer',
            'status' => 'active',
        ]);
    }

    private function vendorMembership(VendorAccount $vendor, User $user): VendorMembership
    {
        return VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function customerMembership(CustomerOrganization $organization, User $user): CustomerMembership
    {
        return CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function role(string $scope, string $slug): Role
    {
        return Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
    }
}
