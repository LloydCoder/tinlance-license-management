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
