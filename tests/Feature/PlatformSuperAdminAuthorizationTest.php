<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerOrganization;
use App\Models\PlatformAccessAuditEvent;
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
        $this->assertDatabaseHas('platform_access_audit_events', [
            'actor_user_id' => $superAdmin->getKey(),
            'action' => 'vendor.read',
            'resource_type' => 'vendor_account',
            'resource_id' => $otherVendor->getKey(),
            'tenant_vendor_account_id' => $otherVendor->getKey(),
            'outcome' => 'allowed',
            'reason_code' => 'platform_super_admin',
        ]);
    }

    public function test_bootstrapped_super_admin_can_view_active_customer_organizations_across_vendor_tenants(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $otherVendor = $this->createVendor('other-customer-vendor');
        $organization = $this->createOrganization($otherVendor, 'cross-tenant-customer');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $organization));
        $this->assertDatabaseHas('platform_access_audit_events', [
            'actor_user_id' => $superAdmin->getKey(),
            'action' => 'customer_organization.read',
            'resource_type' => 'customer_organization',
            'resource_id' => $organization->getKey(),
            'tenant_vendor_account_id' => $otherVendor->getKey(),
            'outcome' => 'allowed',
            'reason_code' => 'platform_super_admin',
        ]);
    }

    public function test_cross_tenant_super_admin_read_is_denied_for_suspended_target_resources(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $suspendedVendor = $this->createVendor('suspended-target', 'suspended');
        $suspendedOrganization = $this->createOrganization($suspendedVendor, 'suspended-customer');

        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $suspendedVendor));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $suspendedOrganization));
        $this->assertDatabaseHas('platform_access_audit_events', [
            'actor_user_id' => $superAdmin->getKey(),
            'action' => 'vendor.read',
            'resource_type' => 'vendor_account',
            'resource_id' => $suspendedVendor->getKey(),
            'outcome' => 'denied',
            'reason_code' => 'tenant_inactive',
        ]);
        $this->assertDatabaseHas('platform_access_audit_events', [
            'actor_user_id' => $superAdmin->getKey(),
            'action' => 'customer_organization.read',
            'resource_type' => 'customer_organization',
            'resource_id' => $suspendedOrganization->getKey(),
            'outcome' => 'denied',
            'reason_code' => 'tenant_inactive',
        ]);
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
        $this->assertDatabaseCount('platform_access_audit_events', 0);
    }

    public function test_bootstrap_super_admin_loses_cross_tenant_read_authority_when_the_super_admin_role_is_revoked(): void
    {
        [$superAdmin, $primaryVendor] = $this->bootstrapSuperAdmin();
        $otherVendor = $this->createVendor('role-revoked-target');
        $membership = VendorMembership::query()
            ->where('vendor_account_id', $primaryVendor->getKey())
            ->where('user_id', $superAdmin->getKey())
            ->firstOrFail();
        $membership->roles()->detach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        $this->assertFalse(Gate::forUser($superAdmin)->allows('view', $otherVendor));
        $this->assertDatabaseCount('platform_access_audit_events', 0);
    }

    public function test_platform_access_audit_events_cannot_be_updated_or_deleted(): void
    {
        [$superAdmin] = $this->bootstrapSuperAdmin();
        $vendor = $this->createVendor('immutable-audit');
        Gate::forUser($superAdmin)->allows('view', $vendor);
        $event = PlatformAccessAuditEvent::query()->sole();

        try {
            $event->update(['reason_code' => 'tampered']);
            $this->fail('Platform access audit event update should be rejected.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $event->delete();
            $this->fail('Platform access audit event deletion should be rejected.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
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
