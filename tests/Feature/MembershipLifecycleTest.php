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
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class MembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_admin_can_revoke_a_member_and_preserve_role_history(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $target = $this->vendorMembership($vendor, $this->createUser());
        $role = $this->role('vendor', 'support_agent');
        $target->roles()->attach($role);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/revoke', [
                'reason' => 'Employee access terminated.',
            ])
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame('revoked', $target->refresh()->status);
        $this->assertTrue($target->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $target->getKey(),
            'actor_user_id' => $actor->getKey(),
            'from_status' => 'active',
            'to_status' => 'revoked',
            'reason' => 'Employee access terminated.',
        ]);
    }

    public function test_customer_owner_can_revoke_a_customer_member(): void
    {
        [$actor, $organization] = $this->customerActor();
        $target = $this->customerMembership($organization, $this->createUser());
        $role = $this->role('customer', 'customer_member');
        $target->roles()->attach($role);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$target->getKey().'/revoke', [
                'reason' => 'Customer staff access removed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame('revoked', $target->refresh()->status);
        $this->assertTrue($target->roles()->whereKey($role->getKey())->exists());
        $this->assertDatabaseHas('customer_membership_lifecycle_events', [
            'customer_membership_id' => $target->getKey(),
            'actor_user_id' => $actor->getKey(),
            'from_status' => 'active',
            'to_status' => 'revoked',
            'reason' => 'Customer staff access removed.',
        ]);
    }

    public function test_vendor_admin_can_cancel_an_invited_membership(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser();
        $target = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $invitee->getKey(),
            'status' => 'invited',
            'joined_at' => null,
        ]);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/revoke', [
                'reason' => 'Invitation withdrawn before acceptance.',
            ])
            ->assertOk()
            ->assertJsonPath('data.changed', true);

        $this->assertSame('revoked', $target->refresh()->status);
        $this->assertDatabaseHas('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $target->getKey(),
            'from_status' => 'invited',
            'to_status' => 'revoked',
        ]);
    }

    public function test_customer_owner_cannot_revoke_their_own_membership(): void
    {
        [$actor, $organization] = $this->customerActor();
        $membership = $actor->customerMemberships()
            ->where('customer_organization_id', $organization->getKey())
            ->firstOrFail();

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$membership->getKey().'/revoke', [
                'reason' => 'Self revocation must use a separate offboarding flow.',
            ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame('active', $membership->refresh()->status);
        $this->assertDatabaseMissing('customer_membership_lifecycle_events', [
            'customer_membership_id' => $membership->getKey(),
        ]);
    }

    public function test_customer_membership_revoke_rejects_a_membership_from_another_organization(): void
    {
        [$actor, $organization] = $this->customerActor();
        $otherVendor = $this->createVendor();
        $otherOrganization = $this->createOrganization($otherVendor);
        $foreignMembership = $this->customerMembership($otherOrganization, $this->createUser());

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$foreignMembership->getKey().'/revoke', [
                'reason' => 'Cross-tenant request must fail.',
            ])
            ->assertNotFound();

        $this->assertSame('active', $foreignMembership->refresh()->status);
    }

    public function test_customer_membership_revocation_fails_when_parent_vendor_is_suspended(): void
    {
        [$actor, $organization] = $this->customerActor();
        $target = $this->customerMembership($organization, $this->createUser());

        VendorAccount::query()
            ->whereKey($organization->vendor_account_id)
            ->update(['status' => 'suspended']);

        $this->authenticatedJson($actor)
            ->postJson(
                '/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$target->getKey().'/revoke',
                ['reason' => 'Suspended vendor must not authorize tenant administration.'],
            )
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame('active', $target->refresh()->status);
        $this->assertDatabaseMissing('customer_membership_lifecycle_events', [
            'customer_membership_id' => $target->getKey(),
        ]);
    }

    public function test_vendor_membership_revocation_fails_when_parent_vendor_is_suspended(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $target = $this->vendorMembership($vendor, $this->createUser());
        VendorAccount::query()->whereKey($vendor->getKey())->update(['status' => 'suspended']);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/revoke', [
                'reason' => 'Suspended vendor must not authorize membership mutation.',
            ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame('active', $target->refresh()->status);
        $this->assertDatabaseMissing('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $target->getKey(),
        ]);
    }

    public function test_membership_lifecycle_events_cannot_be_updated_or_deleted(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $target = $this->vendorMembership($vendor, $this->createUser());

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/revoke', [
                'reason' => 'Preserve immutable lifecycle evidence.',
            ])
            ->assertOk();

        $event = $target->lifecycleEvents()->firstOrFail();

        try {
            $event->update(['reason' => 'tampered']);
            $this->fail('Lifecycle event updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('Preserve immutable lifecycle evidence.', $target->lifecycleEvents()->firstOrFail()->reason);
        }

        try {
            $event->delete();
            $this->fail('Lifecycle event deletion must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseHas('vendor_membership_lifecycle_events', [
                'id' => $event->getKey(),
            ]);
        }
    }

    public function test_actor_cannot_revoke_their_own_vendor_membership(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $membership = $actor->vendorMemberships()->where('vendor_account_id', $vendor->getKey())->firstOrFail();

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/revoke', [
                'reason' => 'Self revocation must use a separate offboarding flow.',
            ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame('active', $membership->refresh()->status);
        $this->assertDatabaseMissing('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $membership->getKey(),
        ]);
    }

    public function test_vendor_membership_revoke_rejects_a_membership_from_another_vendor(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $otherVendor = $this->createVendor();
        $foreignMembership = $this->vendorMembership($otherVendor, $this->createUser());

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$foreignMembership->getKey().'/revoke', [
                'reason' => 'Cross-tenant request must fail.',
            ])
            ->assertNotFound();

        $this->assertSame('active', $foreignMembership->refresh()->status);
    }

    public function test_membership_revoke_requires_fresh_mfa_step_up(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $target = $this->vendorMembership($vendor, $this->createUser());

        $this->actingAs($actor, 'web')
            ->withSession([
                'auth_session_version' => (int) $actor->session_version,
                'auth.password_confirmed_at' => time(),
            ])
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/revoke', [
                'reason' => 'Fresh MFA is mandatory.',
            ])
            ->assertStatus(423)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'MFA_STEP_UP_REQUIRED');

        $this->assertSame('active', $target->refresh()->status);
    }

    public function test_repeated_membership_revocation_is_idempotent_and_does_not_duplicate_events(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $target = $this->vendorMembership($vendor, $this->createUser());
        $path = '/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/revoke';

        $this->authenticatedJson($actor)
            ->postJson($path, ['reason' => 'Offboarding completed.'])
            ->assertOk()
            ->assertJsonPath('data.changed', true);

        $this->authenticatedJson($actor)
            ->postJson($path, ['reason' => 'Duplicate request retry.'])
            ->assertOk()
            ->assertJsonPath('data.changed', false);

        $this->assertSame(
            1,
            DB::table('vendor_membership_lifecycle_events')
                ->where('vendor_membership_id', $target->getKey())
                ->count(),
        );
    }

    private function authenticatedJson(User $user): static
    {
        return $this->actingAs($user, 'web')->withSession([
            'auth_session_version' => (int) User::query()->whereKey($user->getKey())->value('session_version'),
            'auth.password_confirmed_at' => time(),
            'auth.mfa_step_up_at' => time(),
            'auth.mfa_step_up_method' => 'totp',
        ]);
    }

    private function createUser(): User
    {
        $user = User::query()->create([
            'name' => 'Membership Lifecycle Actor',
            'email' => 'membership-lifecycle-'.str()->random(12).'@example.test',
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

    /** @return array{User, VendorAccount} */
    private function vendorActor(): array
    {
        $actor = $this->createUser();
        $vendor = $this->createVendor();
        $membership = $this->vendorMembership($vendor, $actor);
        $membership->roles()->attach($this->role('vendor', 'super_admin'));

        return [$actor, $vendor];
    }

    /** @return array{User, CustomerOrganization} */
    private function customerActor(): array
    {
        $actor = $this->createUser();
        $vendor = $this->createVendor();
        $organization = $this->createOrganization($vendor);
        $membership = $this->customerMembership($organization, $actor);
        $membership->roles()->attach($this->role('customer', 'customer_owner'));

        return [$actor, $organization];
    }

    private function createVendor(): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => 'vendor-'.str()->random(12),
            'legal_name' => 'Membership Lifecycle Vendor Limited',
            'display_name' => 'Membership Lifecycle Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor): CustomerOrganization
    {
        return CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'customer-'.str()->random(12),
            'legal_name' => 'Membership Lifecycle Customer Limited',
            'display_name' => 'Membership Lifecycle Customer',
            'status' => 'active',
        ]);
    }

    private function vendorMembership(VendorAccount $vendor, User $user): VendorMembership
    {
        return VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function customerMembership(CustomerOrganization $organization, User $user): CustomerMembership
    {
        return CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function role(string $scope, string $slug): Role
    {
        return Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
    }
}
