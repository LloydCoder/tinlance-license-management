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
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class MembershipResourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_admin_can_view_only_memberships_under_its_vendor(): void
    {
        $actor = $this->createUser('vendor-admin');
        $vendor = $this->createVendor('primary');
        $otherVendor = $this->createVendor('secondary');
        $this->attachVendorRole($actor, $vendor, 'super_admin');
        $ownMembership = $this->createVendorMembership($vendor, $this->createUser('own-target'));
        $otherMembership = $this->createVendorMembership($otherVendor, $this->createUser('other-target'));

        $this->assertTrue(Gate::forUser($actor)->allows('view', $ownMembership));
        $this->assertFalse(Gate::forUser($actor)->allows('view', $otherMembership));
    }

    public function test_vendor_member_can_view_only_their_own_membership_without_admin_permission(): void
    {
        $vendor = $this->createVendor('self-view');
        $user = $this->createUser('self-view-vendor');
        $ownMembership = $this->createVendorMembership($vendor, $user);
        $ownMembership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail(),
        );
        $otherMembership = $this->createVendorMembership($vendor, $this->createUser('other-vendor-member'));

        $this->assertTrue(Gate::forUser($user)->allows('view', $ownMembership));
        $this->assertFalse(Gate::forUser($user)->allows('view', $otherMembership));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', VendorMembership::class));
    }

    public function test_vendor_self_membership_view_fails_closed_for_inactive_user_or_suspended_vendor(): void
    {
        $inactiveUser = $this->createUser('inactive-self-view');
        $activeVendor = $this->createVendor('inactive-user-vendor');
        $inactiveMembership = $this->createVendorMembership($activeVendor, $inactiveUser);
        $inactiveMembership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail(),
        );
        User::query()->whereKey($inactiveUser->getKey())->update(['status' => 'suspended']);

        $suspendedUser = $this->createUser('suspended-vendor-self-view');
        $suspendedVendor = $this->createVendor('suspended-self-view');
        $suspendedMembership = $this->createVendorMembership($suspendedVendor, $suspendedUser);
        $suspendedMembership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail(),
        );
        VendorAccount::query()->whereKey($suspendedVendor->getKey())->update(['status' => 'suspended']);

        $this->assertFalse(Gate::forUser($inactiveUser)->allows('view', $inactiveMembership));
        $this->assertFalse(Gate::forUser($suspendedUser)->allows('view', $suspendedMembership));
    }

    public function test_vendor_membership_view_requires_vendor_admin_permission_and_active_target(): void
    {
        $vendor = $this->createVendor('support');
        $supportAgent = $this->createUser('support-agent');
        $this->attachVendorRole($supportAgent, $vendor, 'support_agent');
        $activeMembership = $this->createVendorMembership($vendor, $this->createUser('active-target'));
        $revokedMembership = $this->createVendorMembership($vendor, $this->createUser('revoked-target'), 'revoked');

        $this->assertFalse(Gate::forUser($supportAgent)->allows('view', $activeMembership));
        $admin = $this->createUser('vendor-admin');
        $this->attachVendorRole($admin, $vendor, 'super_admin');
        $this->assertFalse(Gate::forUser($admin)->allows('view', $revokedMembership));
    }

    public function test_vendor_membership_policy_does_not_grant_listing_or_mutation_abilities(): void
    {
        $actor = $this->createUser('vendor-admin');
        $vendor = $this->createVendor('no-broad-abilities');
        $this->attachVendorRole($actor, $vendor, 'super_admin');
        $membership = $this->createVendorMembership($vendor, $this->createUser('target'));

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', VendorMembership::class));
        $this->assertFalse(Gate::forUser($actor)->allows('update', $membership));
        $this->assertFalse(Gate::forUser($actor)->allows('delete', $membership));
    }

    public function test_customer_owner_can_view_only_memberships_under_its_organization(): void
    {
        $vendor = $this->createVendor('customer-scope');
        $ownOrganization = $this->createOrganization($vendor, 'own-org');
        $otherOrganization = $this->createOrganization($vendor, 'other-org');
        $owner = $this->createUser('customer-owner');
        $this->attachCustomerRole($owner, $ownOrganization, 'customer_owner');
        $ownMembership = $this->createCustomerMembership($ownOrganization, $this->createUser('own-customer-target'));
        $otherMembership = $this->createCustomerMembership($otherOrganization, $this->createUser('other-customer-target'));

        $this->assertTrue(Gate::forUser($owner)->allows('view', $ownMembership));
        $this->assertFalse(Gate::forUser($owner)->allows('view', $otherMembership));
    }

    public function test_customer_member_can_view_only_their_own_membership_without_members_manage_permission(): void
    {
        $vendor = $this->createVendor('customer-self-view');
        $organization = $this->createOrganization($vendor, 'customer-self-view-org');
        $user = $this->createUser('customer-self-view');
        $ownMembership = $this->createCustomerMembership($organization, $user);
        $ownMembership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );
        $otherMembership = $this->createCustomerMembership(
            $organization,
            $this->createUser('other-customer-member'),
        );

        $this->assertTrue(Gate::forUser($user)->allows('view', $ownMembership));
        $this->assertFalse(Gate::forUser($user)->allows('view', $otherMembership));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', CustomerMembership::class));
    }

    public function test_customer_self_membership_view_fails_closed_for_inactive_user_or_suspended_organization(): void
    {
        $vendor = $this->createVendor('customer-self-inactive');
        $activeOrganization = $this->createOrganization($vendor, 'active-self-org');
        $inactiveUser = $this->createUser('inactive-customer-self');
        $inactiveMembership = $this->createCustomerMembership($activeOrganization, $inactiveUser);
        $inactiveMembership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );
        User::query()->whereKey($inactiveUser->getKey())->update(['status' => 'suspended']);

        $suspendedOrganization = $this->createOrganization($vendor, 'suspended-self-org', 'suspended');
        $activeUser = $this->createUser('suspended-org-self');
        $suspendedMembership = $this->createCustomerMembership($suspendedOrganization, $activeUser);
        $suspendedMembership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($inactiveUser)->allows('view', $inactiveMembership));
        $this->assertFalse(Gate::forUser($activeUser)->allows('view', $suspendedMembership));
    }

    public function test_customer_membership_view_requires_members_manage_permission_and_active_target(): void
    {
        $vendor = $this->createVendor('customer-permission');
        $organization = $this->createOrganization($vendor, 'permission-org');
        $member = $this->createUser('customer-member');
        $this->attachCustomerRole($member, $organization, 'customer_member');
        $activeMembership = $this->createCustomerMembership($organization, $this->createUser('active-customer-target'));
        $revokedMembership = $this->createCustomerMembership($organization, $this->createUser('revoked-customer-target'), 'revoked');

        $this->assertFalse(Gate::forUser($member)->allows('view', $activeMembership));
        $owner = $this->createUser('customer-owner');
        $this->attachCustomerRole($owner, $organization, 'customer_owner');
        $this->assertFalse(Gate::forUser($owner)->allows('view', $revokedMembership));
    }

    public function test_customer_membership_policy_does_not_grant_listing_or_mutation_abilities(): void
    {
        $vendor = $this->createVendor('customer-no-broad');
        $organization = $this->createOrganization($vendor, 'no-broad-org');
        $owner = $this->createUser('customer-owner');
        $this->attachCustomerRole($owner, $organization, 'customer_owner');
        $membership = $this->createCustomerMembership($organization, $this->createUser('target'));

        $this->assertFalse(Gate::forUser($owner)->allows('viewAny', CustomerMembership::class));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $membership));
        $this->assertFalse(Gate::forUser($owner)->allows('delete', $membership));
    }

    private function createUser(string $suffix): User
    {
        return User::create([
            'name' => 'Membership Policy '.$suffix,
            'email' => 'membership-policy-'.$suffix.'-'.str()->random(8).'@example.com',
            'password' => str()->random(32),
            'status' => 'active',
        ]);
    }

    private function createVendor(string $suffix): VendorAccount
    {
        return VendorAccount::create([
            'installation_key' => 'vendor-'.$suffix,
            'legal_name' => 'Vendor '.$suffix,
            'display_name' => 'Vendor '.$suffix,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor, string $reference): CustomerOrganization
    {
        return CustomerOrganization::create([
            'vendor_account_id' => $vendor->id,
            'external_reference' => $reference,
            'legal_name' => 'Customer '.$reference,
            'display_name' => 'Customer '.$reference,
            'status' => 'active',
        ]);
    }

    private function createVendorMembership(
        VendorAccount $vendor,
        User $user,
        string $status = 'active',
    ): VendorMembership {
        return VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);
    }

    private function createCustomerMembership(
        CustomerOrganization $organization,
        User $user,
        string $status = 'active',
    ): CustomerMembership {
        return CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);
    }

    private function attachVendorRole(User $user, VendorAccount $vendor, string $slug): void
    {
        $membership = $this->createVendorMembership($vendor, $user);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', $slug)->firstOrFail(),
        );
    }

    private function attachCustomerRole(User $user, CustomerOrganization $organization, string $slug): void
    {
        $membership = $this->createCustomerMembership($organization, $user);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', $slug)->firstOrFail(),
        );
    }
}
