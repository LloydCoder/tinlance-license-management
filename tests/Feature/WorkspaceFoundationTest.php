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
use Tests\TestCase;

final class WorkspaceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_user_workspace_displays_only_the_current_users_active_tenant_memberships(): void
    {
        $user = $this->createVerifiedUser('workspace-user');
        $ownVendor = $this->createVendor('Own Vendor Workspace');
        $ownMembership = VendorMembership::query()->create([
            'vendor_account_id' => $ownVendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $ownMembership->roles()->attach($this->role('vendor', 'support_agent'));

        $otherUser = $this->createVerifiedUser('workspace-other-user');
        $otherVendor = $this->createVendor('Foreign Vendor Must Not Appear');
        VendorMembership::query()->create([
            'vendor_account_id' => $otherVendor->getKey(),
            'user_id' => $otherUser->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user, 'web')
            ->withSession(['auth_session_version' => (int) $user->session_version])
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Your TLMS workspace')
            ->assertSee('Own Vendor Workspace')
            ->assertSee('Support Agent')
            ->assertDontSee('Foreign Vendor Must Not Appear');
    }

    public function test_customer_user_workspace_displays_only_their_customer_organization(): void
    {
        $user = $this->createVerifiedUser('customer-workspace-user');
        $vendor = $this->createVendor('Customer Workspace Vendor');
        $organization = CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'org-'.str()->random(12),
            'legal_name' => 'Workspace Customer Limited',
            'display_name' => 'Own Customer Workspace',
            'status' => 'active',
        ]);
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('customer', 'customer_member'));

        $this->actingAs($user, 'web')
            ->withSession(['auth_session_version' => (int) $user->session_version])
            ->get('/workspace')
            ->assertOk()
            ->assertSee('Own Customer Workspace')
            ->assertSee('Customer Member');
    }

    public function test_super_admin_workspace_redirects_to_the_administration_dashboard(): void
    {
        $user = $this->createVerifiedUser('workspace-super-admin');
        $vendor = $this->createVendor('Super Admin Workspace Vendor');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('vendor', 'super_admin'));

        $this->actingAs($user, 'web')
            ->withSession(['auth_session_version' => (int) $user->session_version])
            ->get('/workspace')
            ->assertRedirect('/admin');
    }

    public function test_workspace_hides_memberships_when_parent_vendor_is_suspended(): void
    {
        $user = $this->createVerifiedUser('suspended-workspace-user');
        $vendor = $this->createVendor('Suspended Vendor Workspace');
        VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        VendorAccount::query()->whereKey($vendor->getKey())->update(['status' => 'suspended']);

        $this->actingAs($user, 'web')
            ->withSession(['auth_session_version' => (int) $user->session_version])
            ->get('/workspace')
            ->assertOk()
            ->assertDontSee('Suspended Vendor Workspace');
    }

    private function createVerifiedUser(string $label): User
    {
        $user = User::query()->create([
            'name' => 'Workspace '.$label,
            'email' => 'workspace-'.$label.'-'.str()->random(8).'@example.test',
            'password' => str()->random(32),
            'status' => 'active',
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    private function createVendor(string $name): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => 'vendor-'.str()->random(12),
            'legal_name' => $name.' Limited',
            'display_name' => $name,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function role(string $scope, string $slug): Role
    {
        return Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
    }
}
