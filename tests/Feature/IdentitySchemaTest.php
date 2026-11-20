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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class IdentitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_driver_matches_the_explicit_ci_expectation(): void
    {
        $this->assertSame(
            env('TLMS_EXPECTED_DB_CONNECTION', 'sqlite'),
            config('database.default'),
        );
    }

    public function test_users_have_ulid_identity_canonical_email_and_hashed_passwords(): void
    {
        $user = User::create([
            'name' => 'Identity Test',
            'email' => '  Alice@Example.COM ',
            'password' => 'correct-horse-battery-staple',
            'status' => 'active',
        ]);

        $this->assertTrue(Str::isUlid($user->id));
        $this->assertSame('alice@example.com', $user->email);

        $this->assertNotSame('correct-horse-battery-staple', $user->password);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->password));

        $hashInfo = password_get_info($user->password);
        $this->assertSame('argon2id', $hashInfo['algoName']);
        $this->assertGreaterThanOrEqual(19456, $hashInfo['options']['memory_cost']);
        $this->assertGreaterThanOrEqual(2, $hashInfo['options']['time_cost']);
        $this->assertGreaterThanOrEqual(1, $hashInfo['options']['threads']);
    }

    public function test_explicit_bcrypt_fallback_rejects_passwords_over_72_bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Hash::driver('bcrypt')->make(str_repeat('x', 73));
    }

    public function test_user_email_uniqueness_is_case_insensitive(): void
    {
        $this->createUser();

        $this->expectException(QueryException::class);

        User::create([
            'name' => 'Duplicate Identity',
            'email' => 'IDENTITY@EXAMPLE.COM',
            'password' => 'another-correct-password',
            'status' => 'active',
        ]);
    }

    public function test_role_slugs_are_unique_within_scope_not_across_scopes(): void
    {
        $vendorRole = Role::create([
            'scope' => 'vendor',
            'slug' => 'owner',
            'name' => 'Vendor Owner',
            'is_system' => true,
        ]);
        $customerRole = Role::create([
            'scope' => 'customer',
            'slug' => 'owner',
            'name' => 'Customer Owner',
            'is_system' => true,
        ]);

        $this->assertNotSame($vendorRole->id, $customerRole->id);
    }

    public function test_installation_has_a_unique_primary_vendor_account(): void
    {
        $first = VendorAccount::create([
            'installation_key' => 'primary',
            'legal_name' => 'Tinlance Limited',
            'display_name' => 'Tinlance',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);

        $this->assertTrue(Str::isUlid($first->id));

        $this->expectException(QueryException::class);

        VendorAccount::create([
            'installation_key' => 'primary',
            'legal_name' => 'Duplicate Vendor',
            'display_name' => 'Duplicate',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    public function test_vendor_membership_is_unique_per_vendor_and_user(): void
    {
        $vendor = $this->createVendor();
        $user = $this->createUser();

        $membership = VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->assertTrue(Str::isUlid($membership->id));

        $this->expectException(QueryException::class);

        VendorMembership::create([
            'vendor_account_id' => $vendor->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    public function test_customer_membership_is_unique_per_organization_and_user(): void
    {
        $vendor = $this->createVendor();
        $user = $this->createUser();
        $organization = CustomerOrganization::create([
            'vendor_account_id' => $vendor->id,
            'external_reference' => 'customer-001',
            'legal_name' => 'Example Customer Limited',
            'display_name' => 'Example Customer',
            'status' => 'active',
            'billing_email' => 'billing@example.com',
        ]);

        $membership = CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->assertTrue(Str::isUlid($organization->id));
        $this->assertTrue(Str::isUlid($membership->id));

        $this->expectException(QueryException::class);

        CustomerMembership::create([
            'customer_organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    public function test_role_permissions_are_explicit_many_to_many_assignments(): void
    {
        $role = Role::create([
            'scope' => 'vendor',
            'slug' => 'super_admin',
            'name' => 'Super Admin',
            'is_system' => true,
        ]);
        $permission = Permission::create([
            'scope' => 'vendor',
            'name' => 'platform.settings.manage',
            'description' => 'Manage platform settings.',
        ]);

        $role->permissions()->attach($permission);

        $this->assertTrue(Str::isUlid($role->id));
        $this->assertTrue(Str::isUlid($permission->id));
        $this->assertTrue($role->permissions()->whereKey($permission->id)->exists());
        $this->assertTrue($permission->roles()->whereKey($role->id)->exists());
    }

    private function createVendor(): VendorAccount
    {
        return VendorAccount::create([
            'installation_key' => 'primary',
            'legal_name' => 'Tinlance Limited',
            'display_name' => 'Tinlance',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createUser(): User
    {
        return User::create([
            'name' => 'Identity Test',
            'email' => 'identity@example.com',
            'password' => 'correct-horse-battery-staple',
            'status' => 'active',
        ]);
    }
}
