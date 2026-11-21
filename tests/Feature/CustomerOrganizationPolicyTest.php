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

    private function createUser(): User
    {
        return User::create([
            'name' => 'Policy Test',
            'email' => 'policy-'.str()->random(12).'@example.com',
            'password' => 'policy-test-password-value',
            'status' => 'active',
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

    private function createOrganization(VendorAccount $vendor, string $reference): CustomerOrganization
    {
        return CustomerOrganization::create([
            'vendor_account_id' => $vendor->id,
            'external_reference' => $reference,
            'legal_name' => 'Customer '.$reference,
            'display_name' => 'Customer '.$reference,
            'status' => 'active',
            'billing_email' => $reference.'@example.com',
        ]);
    }
}
