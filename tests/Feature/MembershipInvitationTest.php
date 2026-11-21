<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MembershipInvitationMail;
use App\Models\CustomerMembership;
use App\Models\CustomerMembershipLifecycleEvent;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipLifecycleEvent;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class MembershipInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://tlms.example.test']);
        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_admin_can_invite_a_verified_existing_user_and_assign_an_allowlisted_role(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('vendor-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'support_agent',
                'reason' => 'Support team onboarding.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited');

        $membership = VendorMembership::query()->findOrFail($response->json('data.membership_id'));

        $this->assertSame('invited', $membership->status);
        $this->assertSame($actor->getKey(), $membership->invited_by);
        $this->assertTrue($membership->roles()->where('slug', 'support_agent')->exists());

        $token = null;
        Mail::assertSent(MembershipInvitationMail::class, function (MembershipInvitationMail $mail) use ($invitee, &$token): bool {
            $query = [];
            parse_str((string) parse_url($mail->acceptanceUrl, PHP_URL_QUERY), $query);
            $token = is_string($query['token'] ?? null) ? $query['token'] : null;

            return $mail->hasTo($invitee->email)
                && str_starts_with($mail->acceptanceUrl, 'https://tlms.example.test/')
                && is_string($token)
                && strlen($token) >= 64;
        });

        $this->assertIsString($token);
        $invitation = DB::table('vendor_membership_invitations')
            ->where('vendor_membership_id', $membership->getKey())
            ->first();

        $this->assertNotNull($invitation);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
    }

    public function test_customer_owner_can_invite_a_verified_existing_user_with_a_customer_role(): void
    {
        Mail::fake();
        [$actor, $organization] = $this->customerActor();
        $invitee = $this->createUser('customer-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'customer_technical_admin',
                'reason' => 'Customer technical contact onboarding.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited');

        $membership = CustomerMembership::query()->findOrFail($response->json('data.membership_id'));

        $this->assertSame('invited', $membership->status);
        $this->assertTrue($membership->roles()->where('slug', 'customer_technical_admin')->exists());
        Mail::assertSent(MembershipInvitationMail::class, fn (MembershipInvitationMail $mail): bool => $mail->hasTo($invitee->email));
    }

    public function test_invitee_can_accept_only_their_own_unexpired_invitation_once(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('accept-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'support_agent',
                'reason' => 'Invite for acceptance test.',
            ])
            ->assertCreated();

        $token = null;
        Mail::assertSent(MembershipInvitationMail::class, function (MembershipInvitationMail $mail) use (&$token): bool {
            $query = [];
            parse_str((string) parse_url($mail->acceptanceUrl, PHP_URL_QUERY), $query);
            $token = is_string($query['token'] ?? null) ? $query['token'] : null;

            return $token !== null;
        });

        $this->assertIsString($token);
        $membership = VendorMembership::query()->findOrFail($response->json('data.membership_id'));

        $this->actingAs($invitee, 'web')
            ->withSession([
                'auth_session_version' => (int) $invitee->session_version,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->postJson('/api/v1/membership-invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('active', $membership->refresh()->status);
        $this->assertNotNull($membership->joined_at);
        $this->assertDatabaseHas('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $membership->getKey(),
            'actor_user_id' => $invitee->getKey(),
            'from_status' => 'invited',
            'to_status' => 'active',
            'reason' => 'Invitation accepted by the invited user.',
        ]);

        $this->actingAs($invitee, 'web')
            ->withSession([
                'auth_session_version' => (int) $invitee->session_version,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->postJson('/api/v1/membership-invitations/accept', ['token' => $token])
            ->assertStatus(410);
    }

    public function test_invitation_cannot_be_accepted_by_another_verified_user(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('intended-invitee');
        $otherUser = $this->createUser('other-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'support_agent',
                'reason' => 'Email-bound invitation test.',
            ])
            ->assertCreated();

        $token = null;
        Mail::assertSent(MembershipInvitationMail::class, function (MembershipInvitationMail $mail) use (&$token): bool {
            $query = [];
            parse_str((string) parse_url($mail->acceptanceUrl, PHP_URL_QUERY), $query);
            $token = is_string($query['token'] ?? null) ? $query['token'] : null;

            return $token !== null;
        });

        $this->assertIsString($token);
        $membership = VendorMembership::query()->findOrFail($response->json('data.membership_id'));

        $this->actingAs($otherUser, 'web')
            ->withSession([
                'auth_session_version' => (int) $otherUser->session_version,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->postJson('/api/v1/membership-invitations/accept', ['token' => $token])
            ->assertForbidden();

        $this->assertSame('invited', $membership->refresh()->status);
    }

    public function test_resending_an_invitation_invalidates_the_previous_token(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('resend-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'support_agent',
                'reason' => 'Initial invitation.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->findOrFail($response->json('data.membership_id'));
        $oldToken = null;
        Mail::assertSent(MembershipInvitationMail::class, function (MembershipInvitationMail $mail) use (&$oldToken): bool {
            $query = [];
            parse_str((string) parse_url($mail->acceptanceUrl, PHP_URL_QUERY), $query);
            $oldToken = is_string($query['token'] ?? null) ? $query['token'] : null;

            return $oldToken !== null;
        });
        $this->assertIsString($oldToken);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/invitation/resend', [
                'reason' => 'Resend after recipient requested a new link.',
            ])
            ->assertOk();

        $newToken = null;
        Mail::assertSent(MembershipInvitationMail::class, function (MembershipInvitationMail $mail) use (&$newToken): bool {
            $query = [];
            parse_str((string) parse_url($mail->acceptanceUrl, PHP_URL_QUERY), $query);
            $newToken = is_string($query['token'] ?? null) ? $query['token'] : null;

            return $newToken !== null;
        });

        $this->assertIsString($newToken);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertDatabaseHas('vendor_membership_invitations', [
            'vendor_membership_id' => $membership->getKey(),
            'token_hash' => hash('sha256', $oldToken),
        ]);
        $this->assertDatabaseHas('vendor_membership_invitations', [
            'vendor_membership_id' => $membership->getKey(),
            'token_hash' => hash('sha256', $newToken),
            'revoked_at' => null,
            'accepted_at' => null,
        ]);
    }

    public function test_expired_invitation_is_rejected_without_activating_membership(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('expired-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'support_agent',
                'reason' => 'Invitation expiry test.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->findOrFail($response->json('data.membership_id'));
        DB::table('vendor_membership_invitations')
            ->where('vendor_membership_id', $membership->getKey())
            ->update(['expires_at' => now()->subMinute()]);

        $token = null;
        Mail::assertSent(MembershipInvitationMail::class, function (MembershipInvitationMail $mail) use (&$token): bool {
            $query = [];
            parse_str((string) parse_url($mail->acceptanceUrl, PHP_URL_QUERY), $query);
            $token = is_string($query['token'] ?? null) ? $query['token'] : null;

            return $token !== null;
        });

        $this->assertIsString($token);
        $this->actingAs($invitee, 'web')
            ->withSession([
                'auth_session_version' => (int) $invitee->session_version,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->postJson('/api/v1/membership-invitations/accept', ['token' => $token])
            ->assertStatus(410);

        $this->assertSame('invited', $membership->refresh()->status);
        $this->assertDatabaseMissing('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $membership->getKey(),
            'to_status' => 'active',
        ]);
    }

    public function test_invitation_rejects_privileged_role_grants_that_require_maker_checker(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('privileged-invitee');

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'finance_admin',
                'reason' => 'Privileged role must use maker-checker.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('vendor_memberships', [
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $invitee->getKey(),
        ]);
        Mail::assertNothingSent();
    }

    private function authenticatedJson(User $user): static
    {
        return $this->actingAs($user, 'web')->withSession([
            'auth_session_version' => (int) $user->session_version,
            'auth.password_confirmed_at' => now()->timestamp,
            'auth.mfa_step_up_at' => now()->timestamp,
            'auth.mfa_step_up_method' => 'totp',
        ]);
    }

    private function createUser(string $label): User
    {
        $user = User::query()->create([
            'name' => 'Membership Invitation '.$label,
            'email' => 'membership-invitation-'.$label.'-'.str()->random(8).'@example.test',
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
        $actor = $this->createUser('vendor-admin');
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'vendor-'.str()->random(12),
            'legal_name' => 'Invitation Vendor Limited',
            'display_name' => 'Invitation Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
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
    private function customerActor(): array
    {
        $actor = $this->createUser('customer-owner');
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'vendor-'.str()->random(12),
            'legal_name' => 'Invitation Customer Vendor Limited',
            'display_name' => 'Invitation Customer Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
        $organization = CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'customer-'.str()->random(12),
            'legal_name' => 'Invitation Customer Limited',
            'display_name' => 'Invitation Customer',
            'status' => 'active',
        ]);
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $actor->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach($this->role('customer', 'customer_owner'));

        return [$actor, $organization];
    }

    private function role(string $scope, string $slug): Role
    {
        return Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
    }
}
