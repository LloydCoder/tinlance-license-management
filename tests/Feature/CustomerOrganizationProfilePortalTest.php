<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\CustomerOrganizationProfileEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CustomerOrganizationProfilePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_customer_owner_can_view_only_organizations_they_can_manage(): void
    {
        [$owner, $organization] = $this->createCustomerOwner('own-profile');
        [, $otherOrganization] = $this->createCustomerOwner('other-profile');

        $this->actingAs($owner, 'web')
            ->withSession($this->assuredSession($owner))
            ->get('/workspace/customer-profiles')
            ->assertOk()
            ->assertSee('Customer organization profiles')
            ->assertSee($organization->display_name)
            ->assertDontSee($otherOrganization->display_name);
    }

    public function test_customer_owner_can_update_display_name_and_billing_email_with_audit_evidence(): void
    {
        [$owner, $organization] = $this->createCustomerOwner('profile-update');

        $this->actingAs($owner, 'web')
            ->withSession($this->assuredSession($owner))
            ->patch('/workspace/customer-profiles/'.$organization->getKey(), [
                'display_name' => 'Customer Display Updated',
                'billing_email' => 'NEW-BILLING@example.test',
                'reason' => 'Updated billing contact and customer display name.',
            ], ['X-Request-ID' => 'customer-owner-profile-0001'])
            ->assertRedirect('/workspace/customer-profiles')
            ->assertSessionHas('status');

        $organization->refresh();

        $this->assertSame('Customer Display Updated', $organization->display_name);
        $this->assertSame('new-billing@example.test', $organization->billing_email);
        $this->assertDatabaseHas('customer_organization_profile_events', [
            'customer_organization_id' => $organization->getKey(),
            'actor_user_id' => $owner->getKey(),
            'event_type' => 'profile_updated',
            'reason' => 'Updated billing contact and customer display name.',
            'request_id' => 'customer-owner-profile-0001',
        ]);
        $event = CustomerOrganizationProfileEvent::query()
            ->where('customer_organization_id', $organization->getKey())
            ->latest('created_at')
            ->firstOrFail();
        $this->assertEqualsCanonicalizing(['display_name', 'billing_email'], $event->changed_fields);
    }

    public function test_customer_owner_cannot_change_legal_identity_tenant_ownership_or_lifecycle_status(): void
    {
        [$owner, $organization] = $this->createCustomerOwner('profile-fields');
        $otherVendor = $this->createVendor('other-tenant');

        $this->actingAs($owner, 'web')
            ->withSession($this->assuredSession($owner))
            ->patch('/workspace/customer-profiles/'.$organization->getKey(), [
                'display_name' => 'Permitted Display Name',
                'billing_email' => 'allowed@example.test',
                'legal_name' => 'Unauthorized Legal Name',
                'external_reference' => 'unauthorized-reference',
                'vendor_account_id' => $otherVendor->getKey(),
                'status' => 'suspended',
                'reason' => 'Attempted to change vendor-controlled fields.',
            ])
            ->assertSessionHasErrors();

        $organization->refresh();
        $this->assertSame('Customer profile-fields Limited', $organization->legal_name);
        $this->assertSame('profile-fields', $organization->external_reference);
        $this->assertSame('active', $organization->status);
        $this->assertNotSame($otherVendor->getKey(), $organization->vendor_account_id);
        $this->assertSame('Customer profile-fields', $organization->display_name);
    }

    public function test_customer_profile_write_requires_fresh_mfa_step_up(): void
    {
        [$owner, $organization] = $this->createCustomerOwner('no-step-up');

        $this->actingAs($owner, 'web')
            ->withSession([
                'auth_session_version' => (int) $owner->session_version,
                'auth.password_confirmed_at' => (int) now()->timestamp,
            ])
            ->patch('/workspace/customer-profiles/'.$organization->getKey(), [
                'display_name' => 'Must Not Change',
                'billing_email' => 'must-not-change@example.test',
                'reason' => 'Fresh MFA is mandatory for customer profile changes.',
            ])
            ->assertStatus(423);

        $this->assertSame('Customer no-step-up', $organization->refresh()->display_name);
    }

    public function test_customer_billing_admin_and_other_organization_owner_cannot_update_this_profile(): void
    {
        [$owner, $organization] = $this->createCustomerOwner('target-profile');
        [$otherOwner] = $this->createCustomerOwner('other-owner');

        $this->actingAs($otherOwner, 'web')
            ->withSession($this->assuredSession($otherOwner))
            ->patch('/workspace/customer-profiles/'.$organization->getKey(), [
                'display_name' => 'Cross Tenant Attempt',
                'billing_email' => 'cross-tenant@example.test',
                'reason' => 'Cross tenant profile updates are denied.',
            ])
            ->assertForbidden();

        $billingAdmin = $this->createUser('billing-admin');
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $billingAdmin->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_billing_admin')->firstOrFail(),
        );
        $billingAdmin->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($billingAdmin->refresh(), 'web')
            ->withSession($this->assuredSession($billingAdmin))
            ->patch('/workspace/customer-profiles/'.$organization->getKey(), [
                'display_name' => 'Billing Admin Attempt',
                'billing_email' => 'billing-admin@example.test',
                'reason' => 'Billing administrators cannot edit organization profile.',
            ])
            ->assertForbidden();

        $this->assertSame('Customer target-profile', $organization->refresh()->display_name);
    }

    public function test_customer_profile_write_rejects_future_password_confirmation_timestamp(): void
    {
        [$owner, $organization] = $this->createCustomerOwner('future-password-confirmation');

        $this->actingAs($owner, 'web')
            ->withSession([
                'auth_session_version' => (int) $owner->session_version,
                'auth.password_confirmed_at' => (int) now()->addHour()->timestamp,
                'auth.mfa_step_up_at' => (int) now()->timestamp,
                'auth.mfa_step_up_method' => 'totp',
            ])
            ->patch('/workspace/customer-profiles/'.$organization->getKey(), [
                'display_name' => 'Must Not Change',
                'billing_email' => 'must-not-change@example.test',
                'reason' => 'Future password timestamps must not authorize profile changes.',
            ])
            ->assertStatus(423);

        $this->assertSame('Customer future-password-confirmation', $organization->refresh()->display_name);
    }

    /** @return array{User, CustomerOrganization} */
    private function createCustomerOwner(string $reference): array
    {
        $vendor = $this->createVendor('v-'.str()->random(8));
        $organization = CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => $reference,
            'legal_name' => 'Customer '.$reference.' Limited',
            'display_name' => 'Customer '.$reference,
            'status' => 'active',
            'billing_email' => 'billing-'.$reference.'@example.test',
        ]);
        $owner = $this->createUser($reference);
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $owner->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'customer')->where('slug', 'customer_owner')->firstOrFail(),
        );
        $owner->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$owner->refresh(), $organization];
    }

    private function createUser(string $label): User
    {
        return User::query()->create([
            'name' => 'Customer '.$label,
            'email' => 'customer-'.$label.'-'.str()->random(8).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);
    }

    private function createVendor(string $key): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => $key,
            'legal_name' => 'Vendor '.$key.' Limited',
            'display_name' => 'Vendor '.$key,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    /** @return array<string, int|string> */
    private function assuredSession(User $user): array
    {
        return [
            'auth_session_version' => (int) $user->session_version,
            'auth.password_confirmed_at' => (int) now()->timestamp,
            'auth.mfa_step_up_at' => (int) now()->timestamp,
            'auth.mfa_step_up_method' => 'totp',
        ];
    }
}
