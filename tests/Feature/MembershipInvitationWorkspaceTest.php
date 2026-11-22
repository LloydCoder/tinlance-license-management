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
use App\Services\Authorization\MembershipRoleAssignmentService;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            $this->addToAssertionCount(1);
        }

        VendorAccount::query()->whereKey($vendor->getKey())->update(['status' => 'suspended']);

        try {
            app(MembershipInvitationWorkspaceQuery::class)->vendorInvitations($actor, $vendor);
            $this->fail('A suspended vendor must not expose invitations.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
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
            $this->addToAssertionCount(1);
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
        $service = app(MembershipRoleAssignmentService::class);

        $vendorSlugs = $service->assignableVendorRoles()->pluck('slug')->all();
        $customerSlugs = $service->assignableCustomerRoles()->pluck('slug')->all();

        sort($vendorSlugs);
        sort($customerSlugs);

        $this->assertSame(['security_auditor', 'support_agent', 'vendor_developer'], $vendorSlugs);
        $this->assertSame(['customer_billing_admin', 'customer_member', 'customer_technical_admin'], $customerSlugs);
    }

    public function test_workspace_renders_only_vendor_invitations_for_members_with_management_permission(): void
    {
        [$actor, $vendor] = $this->vendorManager('workspace-vendor-admin');
        $invitation = $this->createVendorInvitation(
            $actor,
            $vendor,
            'workspace-vendor-invitee',
            'sent',
            now()->addDay(),
        );

        $this->enableWorkspaceAuthentication($actor);

        $response = $this->actingAs($actor)
            ->withSession($this->workspaceSession($actor))
            ->get(route('workspace.home'));

        $response->assertOk();
        $response->assertViewHas('vendorInvitationGroups', static fn (array $groups): bool => count($groups) === 1);
        $response->assertViewHas('customerInvitationGroups', []);
        $response->assertViewHas('passwordConfirmationFresh', false);
        $response->assertViewHas('mfaStepUpFresh', false);
        $response->assertViewHas('canManageMembershipWrites', false);
    }

    public function test_workspace_does_not_render_vendor_invitations_to_members_without_management_permission(): void
    {
        $actor = $this->createUser('workspace-vendor-member');
        $vendor = $this->createVendor('workspace-vendor-member');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $actor->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('vendor', 'support_agent'));
        $invitation = $this->createVendorInvitation(
            $actor,
            $vendor,
            'workspace-hidden-invitee',
            'sent',
            now()->addDay(),
        );

        $this->enableWorkspaceAuthentication($actor);

        $response = $this->actingAs($actor)
            ->withSession($this->workspaceSession($actor))
            ->get(route('workspace.home'));

        $response->assertOk();
        $response->assertDontSee($invitation->invited_email);
        $response->assertViewHas('vendorInvitationGroups', []);
    }

    public function test_workspace_renders_customer_invitations_only_for_customer_managers(): void
    {
        [$actor, $organization] = $this->customerActor('workspace-customer-manager');
        $invitation = $this->createCustomerInvitation(
            $actor,
            $organization,
            'workspace-customer-invitee',
            'sent',
            now()->addDay(),
        );

        $this->enableWorkspaceAuthentication($actor);

        $response = $this->actingAs($actor)
            ->withSession($this->workspaceSession($actor))
            ->get(route('workspace.home'));

        $response->assertOk();
        $response->assertViewHas('customerInvitationGroups', static fn (array $groups): bool => count($groups) === 1);
        $response->assertViewHas('vendorInvitationGroups', []);
    }

    public function test_vendor_workspace_renders_invitation_controls_and_never_exposes_token_material(): void
    {
        [$actor, $vendor] = $this->vendorManager('workspace-vendor-ui');
        $invitation = $this->createVendorInvitation(
            $actor,
            $vendor,
            'workspace-vendor-ui-invitee',
            'sent',
            now()->addDay(),
        );

        $pendingInvitation = $this->createVendorInvitation(
            $actor,
            $vendor,
            'workspace-vendor-ui-pending',
            'pending',
            now()->addDay(),
        );

        $this->enableWorkspaceAuthentication($actor);
        $session = $this->workspaceSession($actor);
        $session['auth.password_confirmed_at'] = now()->getTimestamp();
        $session['auth.mfa_step_up_at'] = now()->getTimestamp();
        $session['auth.mfa_step_up_method'] = 'totp';

        $response = $this->actingAs($actor)->withSession($session)->get(route('workspace.home'));

        $response->assertOk();
        $response->assertSee('method="post"', false);
        $response->assertSee('name="_token"', false);
        $response->assertSee($invitation->invited_email);
        $response->assertSee($pendingInvitation->invited_email);
        $response->assertSee('Delivery pending');
        $response->assertSee('Created');
        $response->assertSee('support_agent');
        $response->assertSee('security_auditor');
        $response->assertSee('vendor_developer');
        $response->assertDontSee('vendor_admin');
        $response->assertDontSee('finance_admin');
        $response->assertDontSee('vendor_product_manager');
        $response->assertDontSee('reseller_manager');
        $response->assertSee('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations');
        $response->assertSee('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$invitation->vendor_membership_id.'/invitation/resend');
        $response->assertSee('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$invitation->vendor_membership_id.'/revoke');
        $response->assertDontSee($invitation->token_hash);
        $response->assertSee('build/passkeys.js');
        $response->assertSee('build/membership-invitations.js');
        $response->assertViewHas('canManageMembershipWrites', true);
    }

    public function test_customer_workspace_renders_only_customer_scoped_invitation_actions(): void
    {
        [$actor, $organization] = $this->customerActor('workspace-customer-ui');
        $invitation = $this->createCustomerInvitation(
            $actor,
            $organization,
            'workspace-customer-ui-invitee',
            'failed',
            now()->subDay(),
        );

        $this->enableWorkspaceAuthentication($actor);
        $session = $this->workspaceSession($actor);
        $session['auth.password_confirmed_at'] = now()->getTimestamp();
        $session['auth.mfa_step_up_at'] = now()->getTimestamp();
        $session['auth.mfa_step_up_method'] = 'totp';

        $response = $this->actingAs($actor)->withSession($session)->get(route('workspace.home'));

        $response->assertOk();
        $response->assertSee($invitation->invited_email);
        $response->assertSee('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/invitations');
        $response->assertSee('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$invitation->customer_membership_id.'/invitation/resend');
        $response->assertSee('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$invitation->customer_membership_id.'/revoke');
        $response->assertSee('Delivery failed');
        $response->assertSee('Expired');
        $response->assertDontSee($invitation->token_hash);
    }

    public function test_workspace_disables_writes_until_password_confirmation_and_fresh_mfa_step_up(): void
    {
        [$actor, $vendor] = $this->vendorManager('workspace-vendor-assurance');
        $this->createVendorInvitation($actor, $vendor, 'workspace-vendor-assurance-invitee', 'sent', now()->addDay());
        $this->enableWorkspaceAuthentication($actor);

        $response = $this->actingAs($actor)
            ->withSession($this->workspaceSession($actor))
            ->get(route('workspace.home'));

        $response->assertOk();
        $response->assertSee(route('password.confirm'));
        $response->assertSee('data-step-up-totp', false);
        $response->assertSee('Verify authenticator');
        $response->assertSee('disabled', false);
        $response->assertViewHas('passwordConfirmationFresh', false);
        $response->assertViewHas('mfaStepUpFresh', false);
        $response->assertViewHas('canManageMembershipWrites', false);
    }

    private function enableWorkspaceAuthentication(User $user): void
    {
        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('workspace-test-secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    /** @return array<string, int> */
    private function workspaceSession(User $user): array
    {
        $freshUser = $user->fresh();
        if (! $freshUser instanceof User) {
            throw new \RuntimeException('The workspace test user must remain persisted.');
        }

        return ['auth_session_version' => (int) $freshUser->session_version];
    }

    /** @return array{User, VendorAccount} */
    private function vendorManager(string $label): array
    {
        $actor = $this->createUser($label);
        $vendor = $this->createVendor($label);
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $actor->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('vendor', 'vendor_admin'));

        return [$actor, $vendor];
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
        Carbon $expiresAt,
        string $membershipStatus = 'invited',
        ?Carbon $acceptedAt = null,
        ?Carbon $revokedAt = null,
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
        Carbon $expiresAt,
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
