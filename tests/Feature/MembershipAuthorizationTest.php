<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MembershipAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_active_vendor_super_admin_can_use_catalogued_vendor_permission(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $membership->roles()->attach($role);

        $this->assertTrue(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'platform.settings.manage',
        ));
    }

    public function test_customer_role_never_grants_vendor_permission(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $customerRole = Role::query()->where('scope', 'customer')->where('slug', 'customer_owner')->firstOrFail();
        $membership->roles()->attach($customerRole);

        $this->assertFalse(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'platform.settings.manage',
        ));
    }

    public function test_suspended_vendor_membership_is_denied(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'suspended',
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $membership->roles()->attach($role);

        $this->assertFalse(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'platform.settings.manage',
        ));
    }

    public function test_customer_permission_is_limited_to_the_members_users_organization(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $ownOrganization = $this->createCustomerOrganization($vendor, 'customer-own');
        $otherOrganization = $this->createCustomerOrganization($vendor, 'customer-other');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $ownOrganization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'customer')->where('slug', 'customer_technical_admin')->firstOrFail();
        $membership->roles()->attach($role);
        $authorization = app(MembershipAuthorization::class);

        $this->assertTrue($authorization->customerCan($user, $ownOrganization, 'customer.licences.read'));
        $this->assertFalse($authorization->customerCan($user, $otherOrganization, 'customer.licences.read'));
    }

    public function test_inactive_customer_organization_is_denied(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createCustomerOrganization($vendor, 'customer-inactive', 'suspended');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'customer')->where('slug', 'customer_technical_admin')->firstOrFail();
        $membership->roles()->attach($role);

        $this->assertFalse(app(MembershipAuthorization::class)->customerCan(
            $user,
            $organization,
            'customer.licences.read',
        ));
    }

    public function test_inactive_user_is_denied_even_with_an_active_membership(): void
    {
        $user = $this->createUser('suspended');
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $membership->roles()->attach($role);

        $this->assertFalse(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'platform.settings.manage',
        ));
    }

    public function test_vendor_role_never_grants_customer_permission(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createCustomerOrganization($vendor, 'customer-vendor-role');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $vendorRole = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $membership->roles()->attach($vendorRole);

        $this->assertFalse(app(MembershipAuthorization::class)->customerCan(
            $user,
            $organization,
            'customer.licences.read',
        ));
    }

    public function test_inactive_vendor_account_is_denied(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('suspended');
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $membership->roles()->attach($role);

        $this->assertFalse(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'platform.settings.manage',
        ));
    }

    public function test_vendor_membership_rejects_customer_scoped_permission_even_when_attached_to_vendor_role(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $customerPermission = Permission::query()->where('name', 'customer.billing.read')->firstOrFail();
        $role->permissions()->attach($customerPermission);
        $membership->roles()->attach($role);

        $this->assertFalse(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'customer.billing.read',
        ));
    }

    public function test_customer_membership_rejects_vendor_scoped_permission_even_when_attached_to_customer_role(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createCustomerOrganization($vendor, 'customer-cross-scope-permission');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'customer')->where('slug', 'customer_billing_admin')->firstOrFail();
        $vendorPermission = Permission::query()->where('name', 'payments.read')->firstOrFail();
        $role->permissions()->attach($vendorPermission);
        $membership->roles()->attach($role);

        $this->assertFalse(app(MembershipAuthorization::class)->customerCan(
            $user,
            $organization,
            'payments.read',
        ));
    }

    public function test_customer_billing_role_does_not_receive_vendor_payment_permission(): void
    {
        $role = Role::query()->where('scope', 'customer')->where('slug', 'customer_billing_admin')->firstOrFail();

        $this->assertTrue($role->permissions()->where('name', 'customer.billing.read')->exists());
        $this->assertFalse($role->permissions()->where('name', 'payments.read')->exists());
    }

    public function test_suspended_user_is_denied_when_authorization_receives_a_stale_model(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $membership->roles()->attach($role);

        User::query()->whereKey($user->id)->update(['status' => 'suspended']);

        $this->assertFalse(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'platform.settings.manage',
        ));
    }

    public function test_suspended_vendor_is_denied_when_authorization_receives_a_stale_model(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $membership->roles()->attach($role);

        VendorAccount::query()->whereKey($vendor->id)->update(['status' => 'suspended']);

        $this->assertFalse(app(MembershipAuthorization::class)->vendorCan(
            $user,
            $vendor,
            'platform.settings.manage',
        ));
    }

    public function test_suspended_customer_organization_is_denied_when_authorization_receives_a_stale_model(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createCustomerOrganization($vendor, 'customer-stale-model');
        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'customer')->where('slug', 'customer_technical_admin')->firstOrFail();
        $membership->roles()->attach($role);

        CustomerOrganization::query()->whereKey($organization->id)->update(['status' => 'suspended']);

        $this->assertFalse(app(MembershipAuthorization::class)->customerCan(
            $user,
            $organization,
            'customer.licences.read',
        ));
    }

    public function test_authorization_catalog_seeding_is_idempotent(): void
    {
        $roleCount = Role::query()->count();
        $permissionCount = Permission::query()->count();

        $this->seed(AuthorizationCatalogSeeder::class);

        $this->assertSame($roleCount, Role::query()->count());
        $this->assertSame($permissionCount, Permission::query()->count());
    }

    public function test_super_admin_catalog_excludes_secret_readback_and_root_signing_permissions(): void
    {
        $superAdmin = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $permissionNames = $superAdmin->permissions()->pluck('name')->all();

        $this->assertNotContains('integrations.credentials.read_secret', $permissionNames);
        $this->assertNotContains('signing.root.manage', $permissionNames);
        $this->assertNotContains('audit.delete_or_edit', $permissionNames);
    }

    private function createUser(string $status = 'active'): User
    {
        return User::create([
            'name' => 'Authorization Test',
            'email' => 'authorization-'.$status.'-'.str()->random(8).'@example.com',
            'password' => 'correct-horse-battery-staple',
            'status' => $status,
        ]);
    }

    private function createVendor(string $status = 'active'): VendorAccount
    {
        return VendorAccount::create([
            'installation_key' => 'primary',
            'legal_name' => 'Tinlance Limited',
            'display_name' => 'Tinlance',
            'status' => $status,
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createCustomerOrganization(
        VendorAccount $vendor,
        string $reference = 'customer-default',
        string $status = 'active',
    ): CustomerOrganization {
        return CustomerOrganization::create([
            'vendor_account_id' => $vendor->id,
            'external_reference' => $reference,
            'legal_name' => 'Example Customer Limited',
            'display_name' => 'Example Customer',
            'status' => $status,
        ]);
    }
}
