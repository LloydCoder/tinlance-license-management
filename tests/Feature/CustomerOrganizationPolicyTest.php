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

final class CustomerOrganizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_customer_member_can_view_only_its_own_organization(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $own = $this->createOrganization($vendor, 'own');
        $other = $this->createOrganization($vendor, 'other');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $own->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );

        $this->assertTrue(Gate::forUser($user)->allows('view', $own));
        $this->assertFalse(Gate::forUser($user)->allows('view', $other));
    }

    public function test_vendor_support_agent_can_view_customer_organizations_under_its_vendor_only(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $otherVendor = $this->createVendor('other');
        $ownVendorOrganization = $this->createOrganization($vendor, 'vendor-customer');
        $otherVendorOrganization = $this->createOrganization($otherVendor, 'other-vendor-customer');
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail(),
        );

        $this->assertTrue(Gate::forUser($user)->allows('view', $ownVendorOrganization));
        $this->assertFalse(Gate::forUser($user)->allows('view', $otherVendorOrganization));
    }

    public function test_vendor_support_agent_cannot_view_a_suspended_customer_organization(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor, 'suspended-for-support', 'suspended');
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_vendor_support_authorization_uses_persisted_organization_vendor_binding(): void
    {
        $user = $this->createUser();
        $authorizedVendor = $this->createVendor('authorized');
        $actualVendor = $this->createVendor('actual');
        $organization = $this->createOrganization($actualVendor, 'tenant-binding');
        $membership = VendorMembership::create([
            'vendor_account_id' => $authorizedVendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail(),
        );

        // Simulate a caller holding a mutated/stale model; persisted tenant binding remains unchanged.
        $organization->vendor_account_id = $authorizedVendor->id;

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_customer_organization_view_is_denied_without_membership_or_vendor_support_permission(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor, 'unrelated');

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_customer_organization_view_is_denied_when_vendor_is_suspended(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('suspended');
        $organization = $this->createOrganization($vendor, 'suspended-vendor');
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_customer_access_is_denied_when_user_is_inactive(): void
    {
        $user = $this->createUser('suspended');
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor, 'inactive-user');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_customer_access_is_denied_when_organization_is_suspended(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor, 'suspended-org', 'suspended');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_customer_access_is_denied_when_vendor_is_suspended_even_for_an_active_customer_membership(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('suspended');
        $organization = $this->createOrganization($vendor, 'suspended-vendor-customer');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_revoked_customer_membership_is_denied_even_when_the_caller_holds_a_stale_model(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor, 'revoked-membership');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail(),
        );

        $this->assertTrue(Gate::forUser($user)->allows('view', $organization));

        CustomerMembership::query()->whereKey($membership->getKey())->update(['status' => 'revoked']);

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    public function test_removed_customer_role_is_denied_even_when_the_caller_holds_a_stale_model(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor, 'removed-role');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail();
        $membership->roles()->attach($role);

        $this->assertTrue(Gate::forUser($user)->allows('view', $organization));

        $membership->roles()->detach($role);

        $this->assertFalse(Gate::forUser($user)->allows('view', $organization));
    }

    private function createUser(string $status = 'active'): User
    {
        return User::create([
            'name' => 'Policy Test',
            'email' => 'policy-'.str()->random(12).'@example.com',
            'password' => 'policy-test-password-value',
            'status' => $status,
        ]);
    }

    private function createVendor(string $suffix = 'primary'): VendorAccount
    {
        return VendorAccount::create([
            'installation_key' => $suffix === 'primary' ? 'primary' : 'vendor-'.$suffix,
            'legal_name' => 'Tinlance '.$suffix,
            'display_name' => 'Tinlance '.$suffix,
            'status' => $suffix === 'suspended' ? 'suspended' : 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(
        VendorAccount $vendor,
        string $reference,
        string $status = 'active',
    ): CustomerOrganization {
        return CustomerOrganization::create([
            'vendor_account_id' => $vendor->id,
            'external_reference' => $reference,
            'legal_name' => 'Customer '.$reference,
            'display_name' => 'Customer '.$reference,
            'status' => $status,
            'billing_email' => $reference.'@example.com',
        ]);
    }

    public function test_only_primary_vendor_super_admin_can_create_customer_organizations(): void
    {
        $admin = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        $this->assertTrue(Gate::forUser($admin)->allows('create', CustomerOrganization::class));

        $otherAdmin = $this->createUser();
        $otherVendor = $this->createVendor('other');
        $otherMembership = VendorMembership::create([
            'vendor_account_id' => $otherVendor->id,
            'user_id' => $otherAdmin->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $otherMembership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($otherAdmin)->allows('create', CustomerOrganization::class));
    }

    public function test_only_vendor_actor_with_customers_manage_can_update_a_customer_organization(): void
    {
        $admin = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );
        $organization = $this->createOrganization($vendor, 'editable');

        $this->assertTrue(Gate::forUser($admin)->allows('update', $organization));

        $customer = $this->createUser();
        $customerMembership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $customer->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $customerMembership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_owner')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($customer)->allows('update', $organization));
    }


    public function test_customer_owner_can_update_only_its_own_organization_profile(): void
    {
        $vendor = $this->createVendor('profile-owner');
        $own = $this->createOrganization($vendor, 'owner-profile');
        $other = $this->createOrganization($vendor, 'other-profile');

        $owner = $this->createUser();
        $ownerMembership = CustomerMembership::query()->create([
            'customer_organization_id' => $own->getKey(),
            'user_id' => $owner->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $ownerMembership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_owner')->firstOrFail(),
        );

        $billingAdmin = $this->createUser();
        $billingMembership = CustomerMembership::query()->create([
            'customer_organization_id' => $own->getKey(),
            'user_id' => $billingAdmin->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $billingMembership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_billing_admin')->firstOrFail(),
        );

        $this->assertTrue(Gate::forUser($owner)->allows('update', $own));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $other));
        $this->assertFalse(Gate::forUser($billingAdmin)->allows('update', $own));
    }

    public function test_customer_organization_profile_mutations_are_denied_for_suspended_or_foreign_tenants(): void
    {
        $admin = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );
        $foreign = $this->createOrganization($this->createVendor('foreign'), 'foreign');
        $suspended = $this->createOrganization($vendor, 'suspended', 'suspended');

        $this->assertFalse(Gate::forUser($admin)->allows('update', $foreign));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $suspended));
    }
}
