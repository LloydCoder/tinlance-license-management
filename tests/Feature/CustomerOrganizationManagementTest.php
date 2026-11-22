<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerOrganization;
use App\Models\CustomerOrganizationProfileEvent;
use App\Models\PlatformInstallation;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Authorization\CustomerOrganizationManagementService;
use LogicException;
use Tests\TestCase;

final class CustomerOrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_super_admin_can_view_only_customer_organizations_in_the_primary_vendor_installation(): void
    {
        [$admin, $primaryVendor] = $this->createSuperAdmin();
        $own = $this->createOrganization($primaryVendor, 'own-customer');
        $foreign = $this->createOrganization($this->createVendor('foreign'), 'foreign-customer');

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->get('/admin/customers')
            ->assertOk()
            ->assertSee('Customer organizations')
            ->assertSee('Own Customer Customer')
            ->assertDontSee('Foreign Customer');
    }

    public function test_super_admin_can_create_a_customer_organization_and_records_immutable_audit_evidence(): void
    {
        [$admin, $vendor] = $this->createSuperAdmin();

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->post('/admin/customers', [
                'external_reference' => 'acme-001',
                'legal_name' => 'Acme Holdings Limited',
                'display_name' => 'Acme',
                'billing_email' => 'billing@acme.example',
                'reason' => 'Customer onboarding approved by the account owner.',
            ], ['X-Request-ID' => 'customer-create-000001'])
            ->assertRedirect('/admin/customers')
            ->assertSessionHas('status');

        $organization = CustomerOrganization::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('external_reference', 'acme-001')
            ->firstOrFail();

        $this->assertSame('active', $organization->status);
        $this->assertDatabaseHas('customer_organization_profile_events', [
            'customer_organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'event_type' => 'created',
            'reason' => 'Customer onboarding approved by the account owner.',
            'request_id' => 'customer-create-000001',
        ]);
    }

    public function test_super_admin_can_update_customer_profile_without_changing_tenant_ownership(): void
    {
        [$admin, $vendor] = $this->createSuperAdmin();
        $organization = $this->createOrganization($vendor, 'profile-update');

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->patch('/admin/customers/'.$organization->getKey(), [
                'display_name' => 'Updated Customer Name',
                'legal_name' => 'Updated Customer Legal Limited',
                'external_reference' => 'updated-ref',
                'billing_email' => 'new-billing@example.test',
                'reason' => 'Corrected customer master data after verification.',
            ], ['X-Request-ID' => 'customer-update-000001'])
            ->assertRedirect('/admin/customers')
            ->assertSessionHas('status');

        $organization->refresh();

        $this->assertSame('Updated Customer Name', $organization->display_name);
        $this->assertSame('Updated Customer Legal Limited', $organization->legal_name);
        $this->assertSame($vendor->getKey(), $organization->vendor_account_id);
        $this->assertDatabaseHas('customer_organization_profile_events', [
            'customer_organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'event_type' => 'profile_updated',
            'request_id' => 'customer-update-000001',
        ]);
    }

    public function test_customer_profile_write_requires_fresh_mfa_step_up(): void
    {
        [$admin, $vendor] = $this->createSuperAdmin();

        $this->actingAs($admin, 'web')
            ->withSession([
                'auth_session_version' => (int) $admin->session_version,
                'auth.password_confirmed_at' => (int) now()->timestamp,
            ])
            ->post('/admin/customers', [
                'legal_name' => 'No Step Up Limited',
                'display_name' => 'No Step Up',
                'reason' => 'Must not create without fresh MFA.',
            ])
            ->assertStatus(423);

        $this->assertDatabaseMissing('customer_organizations', [
            'vendor_account_id' => $vendor->getKey(),
            'display_name' => 'No Step Up',
        ]);
    }

    public function test_customer_organization_cannot_be_moved_to_another_vendor_by_profile_update(): void
    {
        [$admin, $vendor] = $this->createSuperAdmin();
        $organization = $this->createOrganization($vendor, 'ownership-stable');

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->patch('/admin/customers/'.$organization->getKey(), [
                'vendor_account_id' => $this->createVendor('attacker-controlled')->getKey(),
                'display_name' => 'Attempted Transfer',
                'legal_name' => 'Attempted Transfer Limited',
                'reason' => 'This request must not change tenant ownership.',
            ])
            ->assertSessionHasErrors();

        $this->assertSame($vendor->getKey(), $organization->refresh()->vendor_account_id);
    }

    public function test_duplicate_external_reference_is_rejected_within_the_primary_vendor(): void
    {
        [$admin, $vendor] = $this->createSuperAdmin();
        $this->createOrganization($vendor, 'duplicate-ref');

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->post('/admin/customers', [
                'external_reference' => 'duplicate-ref',
                'legal_name' => 'Duplicate Limited',
                'display_name' => 'Duplicate',
                'reason' => 'Duplicate external references are prohibited.',
            ])
            ->assertSessionHasErrors('external_reference');
    }


    public function test_platform_bootstrap_super_admin_cannot_mutate_customer_records_through_the_service_layer(): void
    {
        [$admin] = $this->createSuperAdmin();
        PlatformInstallation::query()
            ->where('installation_key', 'primary')
            ->update([
                'bootstrapped_user_id' => $admin->getKey(),
                'bootstrapped_at' => now(),
            ]);

        $this->expectException(AuthorizationException::class);

        app(CustomerOrganizationManagementService::class)->create(
            $admin,
            [
                'legal_name' => 'Unauthorized Platform Mutation Limited',
                'display_name' => 'Unauthorized Platform Mutation',
                'external_reference' => null,
                'billing_email' => null,
            ],
            'Platform bootstrap identity is read-only across tenant resources.',
            'platform-mutation-denied-0001',
        );
    }

    public function test_customer_profile_audit_events_cannot_be_updated_through_eloquent(): void
    {
        [$admin, $vendor] = $this->createSuperAdmin();
        $organization = $this->createOrganization($vendor, 'immutable-update');
        $event = CustomerOrganizationProfileEvent::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'event_type' => 'profile_updated',
            'reason' => 'Record the initial verified profile change.',
            'changed_fields' => ['display_name'],
            'request_id' => 'profile-event-update-0001',
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->reason = 'Tampered audit reason.';
        $event->save();
    }

    public function test_customer_profile_audit_events_cannot_be_deleted_through_eloquent(): void
    {
        [$admin, $vendor] = $this->createSuperAdmin();
        $organization = $this->createOrganization($vendor, 'immutable-delete');
        $event = CustomerOrganizationProfileEvent::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'event_type' => 'profile_updated',
            'reason' => 'Record the initial verified profile change.',
            'changed_fields' => ['display_name'],
            'request_id' => 'profile-event-delete-0001',
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $event->delete();
    }

    /** @return array{User, VendorAccount} */
    private function createSuperAdmin(): array
    {
        $vendor = $this->createVendor('primary');
        $admin = $this->createUser('super-admin');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $admin->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        $admin->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$admin->refresh(), $vendor];
    }

    private function createUser(string $label): User
    {
        return User::query()->create([
            'name' => 'Customer Admin '.$label,
            'email' => 'customer-admin-'.$label.'-'.str()->random(8).'@example.test',
            'password' => str()->random(32),
            'status' => 'active',
        ]);
    }

    private function createVendor(string $label): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => $label === 'primary' ? 'primary' : 'vendor-'.$label,
            'legal_name' => 'Vendor '.$label.' Limited',
            'display_name' => 'Vendor '.$label,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor, string $reference): CustomerOrganization
    {
        return CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => $reference,
            'legal_name' => 'Customer '.$reference.' Limited',
            'display_name' => ucwords(str_replace('-', ' ', $reference)).' Customer',
            'status' => 'active',
            'billing_email' => 'billing-'.$reference.'@example.test',
        ]);
    }

    /** @return array<string, int|string> */
    private function assuredSession(User $admin): array
    {
        return [
            'auth_session_version' => (int) $admin->session_version,
            'auth.password_confirmed_at' => (int) now()->timestamp,
            'auth.mfa_step_up_at' => (int) now()->timestamp,
            'auth.mfa_step_up_method' => 'totp',
        ];
    }
}
