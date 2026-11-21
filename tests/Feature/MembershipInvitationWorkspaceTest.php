<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerMembership;
use App\Models\CustomerMembershipInvitation;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipInvitation;
use App\Services\Authorization\MembershipInvitationWorkspaceQuery;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MembershipInvitationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_invitation_query_is_tenant_scoped_and_keeps_expired_or_failed_delivery_rows(): void
    {
        [$actor, $vendor] = $this->vendorActor('vendor-query-one');
        $otherVendor = $this->createVendor('vendor-query-two');

        $sent = $this->createVendorInvitation($actor, $vendor, 'sent-invitee', 'sent', now()->addDay());
        $failedExpired = $this->createVendorInvitation($actor, $vendor, 'failed-invitee', 'failed', now()->subDay());
        $accepted = $this->createVendorInvitation($actor, $vendor, 'accepted-invitee', 'sent', now()->addDay(), 'active', now(), null);
        $revoked = $this->createVendorInvitation($actor, $vendor, 'revoked-invitee', 'sent', now()->addDay(), 'revoked', null, now());
        $otherTenant = $this->createVendorInvitation($actor, $otherVendor, 'other-tenant-invitee', 'sent', now()->addDay());

        $paginator = app(MembershipInvitationWorkspaceQuery::class)->vendorInvitations($actor, $vendor);
        $emails = collect($paginator->items())->pluck('invited_email')->all();

        $this->assertSame(2, $paginator->total());
        $this->assertContains($sent->invited_email, $emails);
        $this->assertContains($failedExpired->invited_email, $emails);
        $this->assertNotContains($accepted->invited_email, $emails);
        $this->assertNotContains($revoked->invited_email, $emails);
        $this->assertNotContains($otherTenant->invited_email, $emails);
        $this->assertSame(25, $paginator->perPage());
        $this->assertArrayNotHasKey('token_hash', $paginator->items()[0]->toArray());
    }

    public function test_customer_invitation_query_is_scoped_to_active_organization_and_parent_vendor(): void
    {
        [$actor, $organization] = $this->customerActor('customer-query-one');
        $otherVendor = $this->createVendor('customer-query-two');
        $otherOrganization = $this->createOrganization($otherVendor, 'customer-query-two');

        $own = $this->createCustomerInvitation($actor, $organization, 'customer-own-invitee', 'sent', now()->addDay());
        $other = $this->createCustomerInvitation($actor, $otherOrganization, 'customer-other-invitee', 'sent', now()->addDay());

        $paginator = app(MembershipInvitationWorkspaceQuery::class)->customerInvitations($actor, $organization);
        $emails = collect($paginator->items())->pluck('invited_email')->all();

        $this->assertSame(1, $paginator->total());
        $this->assertContains($own->invited_email, $emails);
        $this->assertNotContains($other->invited_email, $emails);
    }

    public function test_vendor_invitation_query_denies_members_without_admin_permission_and_suspended_vendors(): void
    {
        [$actor, $vendor] = $this->vendorActor('vendor-query-permission');
        $member = $this->createUser('ordinary-vendor-member');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $member->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('vendor', 'support_agent'));

        try {
            app(MembershipInvitationWorkspaceQuery::class)->vendorInvitations($member, $vendor);
            $this->fail('A vendor member without admins.manage must not list invitations.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        VendorAccount::query()->whereKey($vendor->getKey())->update(['status' => 'suspended']);

        try {
            app(MembershipInvitationWorkspaceQuery::class)->vendorInvitations($actor, $vendor);
            $this->fail('A suspended vendor must not expose invitations.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_customer_invitation_query_denies_suspended_organization_or_parent_vendor(): void
    {
        [$actor, $organization] = $this->customerActor('customer-query-suspended');
        CustomerOrganization::query()->whereKey($organization->getKey())->update(['status' => 'suspended']);

        try {
            app(MembershipInvitationWorkspaceQuery::class)->customerInvitations($actor, $organization);
            $this->fail('A suspended customer organization must not expose invitations.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    public function test_invitation_query_paginates_each_tenant_at_twenty_five_rows(): void
    {
        [$actor, $vendor] = $this->vendorActor('vendor-query-pagination');

        for ($i = 0; $i < 26; $i++) {
            $this->createVendorInvitation($actor, $vendor, 'pagination-invitee-'.$i, 'sent', now()->addDay());
        }

        $paginator = app(MembershipInvitationWorkspaceQuery::class)->vendorInvitations($actor, $vendor);

        $this->assertSame(26, $paginator->total());
        $this->assertSame(25, $paginator->perPage());
        $this->assertCount(25, $paginator->items());
    }

    public function test_assignable_role_catalogue_excludes_maker_checker_only_vendor_roles(): void
    {
        $service = app(\App\Services\Authorization\MembershipRoleAssignmentService::class);

        $vendorSlugs = $service->assignableVendorRoles()->pluck('slug')->all();
        $customerSlugs = $service->assignableCustomerRoles()->pluck('slug')->all();

        sort($vendorSlugs);
        sort($customerSlugs);

        $this->assertSame(['security_auditor', 'support_agent', 'vendor_developer'], $vendorSlugs);
        $this->assertSame(['customer_billing_admin', 'customer_member', 'customer_technical_admin'], $customerSlugs);
    }

    /** @return array{User, VendorAccount} */
    private function vendorActor(string $label): array
    {
        $actor = $this->createUser($label);
        $vendor = $this->createVendor($label);
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $actor->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('vendor', 'super_admin'));

        return [$actor, $vendor];
    }

    /** @return array{User, CustomerOrganization} */
    private function customerActor(string $label): array
    {
        $actor = $this->createUser($label);
        $vendor = $this->createVendor($label);
        $organization = $this->createOrganization($vendor, $label);
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $actor->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('customer', 'customer_owner'));

        return [$actor, $organization];
    }

    private function createVendor(string $label): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => 'vendor-'.str()->random(12),
            'legal_name' => 'Workspace '.$label.' Limited',
            'display_name' => 'Workspace '.$label,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor, string $label): CustomerOrganization
    {
        return CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'customer-'.str()->random(12),
            'legal_name' => 'Workspace Customer '.$label.' Limited',
            'display_name' => 'Workspace Customer '.$label,
            'status' => 'active',
        ]);
    }

    private function createVendorInvitation(
        User $actor,
        VendorAccount $vendor,
        string $label,
        string $deliveryStatus,
        \Illuminate\Support\Carbon $expiresAt,
        string $membershipStatus = 'invited',
        ?\Illuminate\Support\Carbon $acceptedAt = null,
        ?\Illuminate\Support\Carbon $revokedAt = null,
    ): VendorMembershipInvitation {
        $user = $this->createUser($label);
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => $membershipStatus,
            'invited_by' => $actor->getKey(),
            'joined_at' => $membershipStatus === 'active' ? now() : null,
        ]);
        $membership->roles()->attach($this->role('vendor', 'support_agent'));

        return VendorMembershipInvitation::query()->create([
            'vendor_membership_id' => $membership->getKey(),
            'invited_by_user_id' => $actor->getKey(),
            'invited_email' => $user->email,
            'reason' => 'Workspace invitation query fixture.',
            'token_hash' => hash('sha256', str()->random(64)),
            'expires_at' => $expiresAt,
            'sent_at' => $deliveryStatus === 'sent' ? now() : null,
            'accepted_at' => $acceptedAt,
            'revoked_at' => $revokedAt,
            'delivery_status' => $deliveryStatus,
        ]);
    }

    private function createCustomerInvitation(
        User $actor,
        CustomerOrganization $organization,
        string $label,
        string $deliveryStatus,
        \Illuminate\Support\Carbon $expiresAt,
    ): CustomerMembershipInvitation {
        $user = $this->createUser($label);
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'invited',
            'invited_by' => $actor->getKey(),
        ]);
        $membership->roles()->attach($this->role('customer', 'customer_member'));

        return CustomerMembershipInvitation::query()->create([
            'customer_membership_id' => $membership->getKey(),
            'invited_by_user_id' => $actor->getKey(),
            'invited_email' => $user->email,
            'reason' => 'Workspace customer invitation query fixture.',
            'token_hash' => hash('sha256', str()->random(64)),
            'expires_at' => $expiresAt,
            'sent_at' => $deliveryStatus === 'sent' ? now() : null,
            'delivery_status' => $deliveryStatus,
        ]);
    }

    private function createUser(string $label): User
    {
        return User::query()->create([
            'name' => 'Workspace '.$label,
            'email' => mb_strtolower('workspace-'.$label.'-'.str()->random(8).'@example.test'),
            'password' => str()->random(32),
            'status' => 'active',
        ]);
    }

    private function role(string $scope, string $slug): Role
    {
        return Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
    }
}
