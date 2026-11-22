<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerOrganization;
use App\Models\PlatformInstallation;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class PlatformSuperAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_bootstrapped_super_admin_can_view_active_vendor_accounts_across_vendor_tenants(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $otherVendor = $this->createVendor('other-vendor');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $otherVendor));
    }

    public function test_bootstrapped_super_admin_can_view_active_customer_organizations_across_vendor_tenants(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $otherVendor = $this->createVendor('other-customer-vendor');
        $organization = $this->createOrganization($otherVendor, 'cross-tenant-customer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $organization));
    }

    public function test_cross_tenant_super_admin_read_is_denied_for_suspended_target_resources(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $suspendedVendor = $this->createVendor('suspended-target', 'suspended');
        $suspendedOrganization = $this->createOrganization($suspendedVendor, 'suspended-customer');

        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $suspendedVendor));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $suspendedOrganization));
    }

    public function test_cross_tenant_super_admin_read_is_denied_after_bootstrap_user_suspension(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $otherVendor = $this->createVendor('after-suspension');

        User::query()->whereKey($superAdmin->getKey())->update(['status' => 'suspended']);

        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $otherVendor));
    }

    public function test_vendor_scoped_super_admin_role_without_bootstrap_authority_cannot_read_other_tenants(): void
    {
        $user = $this->createUser('vendor-super-admin');
        $ownVendor = $this->createVendor('own-vendor');
        $otherVendor = $this->createVendor('other-vendor');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $ownVendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($user)->allows('view', $otherVendor));
    }

    public function test_platform_super_admin_read_policy_does_not_grant_resource_mutations_or_collection_listing(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $vendor = $this->createVendor('read-only-target');
        $organization = $this->createOrganization($vendor, 'read-only-customer');

        $this->assertFalse(Gate::forUser($superAdmin)->allows('viewAny', VendorAccount::class));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('update', $vendor));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $vendor));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('viewAny', CustomerOrganization::class));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('update', $organization));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('delete', $organization));
    }

    /** @return array{0: User, 1: VendorAccount} */
    private function bootstrapSuperAdmin(): array
    {
        $user = $this->createUser('platform-super-admin');
        $primaryVendor = $this->createVendor('primary');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $primaryVendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        PlatformInstallation::query()
            ->where('installation_key', 'primary')
            ->update([
                'bootstrapped_user_id' => $user->getKey(),
                'bootstrapped_at' => now(),
            ]);

        return [$user, $primaryVendor];
    }

    private function createUser(string $suffix): User
    {
        return User::query()->create([
            'name' => 'Authorization '.$suffix,
            'email' => $suffix.'-'.str()->random(10).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);
    }

    private function createVendor(string $suffix, string $status = 'active'): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => $suffix,
            'legal_name' => 'Vendor '.$suffix.' Ltd',
            'display_name' => 'Vendor '.$suffix,
            'status' => $status,
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor, string $reference): CustomerOrganization
    {
        return CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => $reference,
            'legal_name' => 'Customer '.$reference.' Ltd',
            'display_name' => 'Customer '.$reference,
            'status' => 'active',
            'billing_email' => $reference.'@example.test',
        ]);
    }
}
