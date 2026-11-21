<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class VendorAccountPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_member_with_vendor_account_read_permission_can_view_its_vendor_only(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('own');
        $otherVendor = $this->createVendor('other');
        $this->attachVendorRole($user, $vendor, 'support_agent');

        $this->assertTrue(Gate::forUser($user)->allows('view', $vendor));
        $this->assertFalse(Gate::forUser($user)->allows('view', $otherVendor));
    }

    public function test_vendor_account_view_is_denied_without_an_active_vendor_membership(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('unrelated');

        $this->assertFalse(Gate::forUser($user)->allows('view', $vendor));
    }

    public function test_vendor_account_view_is_denied_for_inactive_users_and_suspended_vendors(): void
    {
        $inactiveUser = $this->createUser('suspended');
        $activeUser = $this->createUser();
        $suspendedVendor = $this->createVendor('suspended', 'suspended');
        $activeVendor = $this->createVendor('active');
        $this->attachVendorRole($inactiveUser, $activeVendor, 'support_agent');
        $this->attachVendorRole($activeUser, $suspendedVendor, 'support_agent');

        $this->assertFalse(Gate::forUser($inactiveUser)->allows('view', $activeVendor));
        $this->assertFalse(Gate::forUser($activeUser)->allows('view', $suspendedVendor));
    }

    public function test_vendor_account_view_is_denied_after_membership_revocation_even_with_stale_user_and_vendor_models(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('revoked');
        $membership = $this->attachVendorRole($user, $vendor, 'support_agent');

        $this->assertTrue(Gate::forUser($user)->allows('view', $vendor));

        VendorMembership::query()->whereKey($membership->getKey())->update(['status' => 'revoked']);

        $this->assertFalse(Gate::forUser($user)->allows('view', $vendor));
    }

    public function test_vendor_account_view_is_denied_after_vendor_role_revocation(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('role-revoked');
        $membership = $this->attachVendorRole($user, $vendor, 'support_agent');
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'support_agent')->firstOrFail();

        $this->assertTrue(Gate::forUser($user)->allows('view', $vendor));

        $membership->roles()->detach($role);

        $this->assertFalse(Gate::forUser($user)->allows('view', $vendor));
    }

    public function test_customer_scope_role_cannot_authorize_vendor_account_access(): void
    {
        $user = $this->createUser();
        $vendor = $this->createVendor('wrong-scope');
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $customerRole = Role::query()->where('scope', 'customer')->where('slug', 'customer_member')->firstOrFail();
        $membership->roles()->attach($customerRole);

        $this->assertFalse(Gate::forUser($user)->allows('view', $vendor));
    }

    private function createUser(string $status = 'active'): User
    {
        return User::create([
            'name' => 'Vendor Policy Test',
            'email' => 'vendor-policy-'.str()->random(12).'@example.com',
            'password' => str()->random(32),
            'status' => $status,
        ]);
    }

    private function createVendor(string $suffix, string $status = 'active'): VendorAccount
    {
        return VendorAccount::create([
            'installation_key' => 'vendor-'.$suffix,
            'legal_name' => 'Vendor '.$suffix,
            'display_name' => 'Vendor '.$suffix,
            'status' => $status,
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function attachVendorRole(User $user, VendorAccount $vendor, string $slug): VendorMembership
    {
        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $role = Role::query()->where('scope', 'vendor')->where('slug', $slug)->firstOrFail();
        $membership->roles()->attach($role);

        return $membership;
    }
}
