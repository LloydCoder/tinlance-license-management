<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\MembershipInvitationMail;
use App\Models\CustomerMembership;
use App\Models\CustomerMembershipInvitation;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipInvitation;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_vendor_admin_can_invite_a_new_user_with_a_hashed_single_use_token(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $email = mb_strtolower('new-vendor-member-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $email,
                'name' => 'New Vendor Member',
                'role_slug' => 'support_agent',
                'reason' => 'Support team onboarding.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited');

        $membership = VendorMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $invitee = User::query()->where('email', $email)->firstOrFail();

        $this->assertNull($invitee->email_verified_at);
        $this->assertSame('active', $invitee->status);
        $this->assertSame('invited', $membership->status);
        $this->assertSame($actor->getKey(), $membership->invited_by);
        $this->assertTrue($membership->roles()->where('slug', 'support_agent')->exists());

        $tokens = $this->sentInvitationTokens($email);
        $this->assertCount(1, $tokens);
        $token = $tokens[0];
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);

        $invitation = DB::table('vendor_membership_invitations')
            ->where('vendor_membership_id', $membership->getKey())
            ->first();

        $this->assertNotNull($invitation);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame('sent', $invitation->delivery_status);
        $this->assertNotNull($invitation->sent_at);
    }

    public function test_customer_owner_can_invite_a_new_user_with_a_customer_role(): void
    {
        Mail::fake();
        [$actor, $organization] = $this->customerActor();
        $email = mb_strtolower('new-customer-member-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/invitations', [
                'email' => $email,
                'name' => 'New Customer Member',
                'role_slug' => 'customer_technical_admin',
                'reason' => 'Customer technical contact onboarding.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited');

        $membership = CustomerMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();

        $this->assertSame('invited', $membership->status);
        $this->assertNull(User::query()->whereKey($membership->user_id)->value('email_verified_at'));
        $this->assertTrue($membership->roles()->where('slug', 'customer_technical_admin')->exists());
        $this->assertCount(1, $this->sentInvitationTokens($email));
    }

    public function test_new_customer_invitee_can_set_password_verify_email_and_accept_membership(): void
    {
        Mail::fake();
        [$actor, $organization] = $this->customerActor();
        $email = mb_strtolower('accept-new-customer-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/invitations', [
                'email' => $email,
                'name' => 'Pending Customer Member',
                'role_slug' => 'customer_member',
                'reason' => 'Customer invitation acceptance test.',
            ])
            ->assertCreated();

        $membership = CustomerMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $token = $this->sentInvitationTokens($email)[0];

        Auth::logout();

        $this->postJson('/membership-invitations/prepare', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('next', 'account_setup');

        $password = str()->random(40).'Bb8!';
        $this->post('/membership-invitations/accept', [
            'name' => 'Accepted Customer Member',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertRedirect('/login');

        $invitee = User::query()->where('email', $email)->firstOrFail();
        $this->assertNotNull($invitee->email_verified_at);
        $this->assertTrue(Hash::check($password, $invitee->password));
        $this->assertSame('active', $membership->refresh()->status);
        $this->assertDatabaseHas('customer_membership_lifecycle_events', [
            'customer_membership_id' => $membership->getKey(),
            'actor_user_id' => $invitee->getKey(),
            'from_status' => 'invited',
            'to_status' => 'active',
            'reason' => 'Invitation accepted by the invited user.',
        ]);
    }

    public function test_new_invitee_can_set_password_verify_email_and_accept_membership(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $email = mb_strtolower('accept-new-member-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $email,
                'name' => 'Pending Member',
                'role_slug' => 'support_agent',
                'reason' => 'Invitation acceptance test.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $token = $this->sentInvitationTokens($email)[0];

        Auth::logout();

        $this->postJson('/membership-invitations/prepare', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('next', 'account_setup');

        $this->get('/membership-invitations/accept')
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertSee('Confirm your name and set a strong password.');

        $password = str()->random(40).'Aa9!';
        $this->post('/membership-invitations/accept', [
            'name' => 'Accepted Vendor Member',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertRedirect('/login');

        $invitee = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('Accepted Vendor Member', $invitee->name);
        $this->assertNotNull($invitee->email_verified_at);
        $this->assertTrue(Hash::check($password, $invitee->password));
        $this->assertSame('active', $membership->refresh()->status);
        $this->assertNotNull($membership->joined_at);

        $this->post('/login', ['email' => $email, 'password' => $password])
            ->assertRedirect('/workspace');
        $this->get('/workspace')
            ->assertRedirect(route('security.mfa.enroll'));

        $this->assertDatabaseHas('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $membership->getKey(),
            'actor_user_id' => $invitee->getKey(),
            'from_status' => 'invited',
            'to_status' => 'active',
            'reason' => 'Invitation accepted by the invited user.',
        ]);
        $acceptedInvitation = DB::table('vendor_membership_invitations')
            ->where('vendor_membership_id', $membership->getKey())
            ->where('token_hash', hash('sha256', $token))
            ->first();
        $this->assertNotNull($acceptedInvitation?->accepted_at);
    }

    public function test_authenticated_different_account_cannot_complete_new_account_invitation_setup(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $email = mb_strtolower('session-confusion-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $email,
                'role_slug' => 'support_agent',
                'reason' => 'Protect account setup from session confusion.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $token = $this->sentInvitationTokens($email)[0];
        $invitee = User::query()->where('email', $email)->firstOrFail();
        $otherUser = $this->createVerifiedUser('session-confusion-other');

        Auth::logout();
        $this->postJson('/membership-invitations/prepare', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('next', 'account_setup');

        $this->actingAs($otherUser, 'web')
            ->withSession(['auth_session_version' => (int) $otherUser->session_version])
            ->post('/membership-invitations/accept', [
                'name' => 'Attacker Controlled Name',
                'password' => 'A-very-strong-password-2026!',
                'password_confirmation' => 'A-very-strong-password-2026!',
            ])
            ->assertForbidden();

        $this->assertNull($invitee->refresh()->email_verified_at);
        $this->assertSame('invited', $membership->refresh()->status);
        $this->assertDatabaseMissing('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $membership->getKey(),
            'to_status' => 'active',
        ]);
    }

    public function test_verified_existing_invitee_must_authenticate_as_the_invited_account(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createVerifiedUser('existing-invitee');
        $otherUser = $this->createVerifiedUser('other-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'support_agent',
                'reason' => 'Existing account invitation.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $token = $this->sentInvitationTokens($invitee->email)[0];

        Auth::logout();

        $this->postJson('/membership-invitations/prepare', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('next', 'login');

        $this->actingAs($otherUser, 'web')
            ->withSession(['auth_session_version' => (int) $otherUser->session_version])
            ->get('/membership-invitations/accept')
            ->assertForbidden();

        $this->actingAs($invitee, 'web')
            ->withSession([
                'auth_session_version' => (int) $invitee->session_version,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->get('/membership-invitations/accept')
            ->assertOk()
            ->assertSee('Confirm acceptance while signed in to the invited account.');

        $this->post('/membership-invitations/accept/authenticated')
            ->assertRedirect('/membership-invitations/accepted');

        $this->assertSame('active', $membership->refresh()->status);
        $this->assertNotNull($membership->joined_at);
    }

    public function test_customer_invitation_for_verified_existing_user_activates_only_once(): void
    {
        Mail::fake();
        [$actor, $organization] = $this->customerActor();
        $invitee = $this->createVerifiedUser('existing-customer-invitee');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/invitations', [
                'email' => $invitee->email,
                'role_slug' => 'customer_technical_admin',
                'reason' => 'Existing customer technical contact onboarding.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited');

        $membership = CustomerMembership::query()
            ->whereKey((string) $response->json('data.membership_id'))
            ->firstOrFail();
        $token = $this->sentInvitationTokens($invitee->email)[0];

        Auth::logout();
        $this->postJson('/membership-invitations/prepare', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('next', 'login');

        $this->actingAs($invitee, 'web')
            ->withSession([
                'auth_session_version' => (int) $invitee->session_version,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->get('/membership-invitations/accept')
            ->assertOk()
            ->assertSee('Confirm acceptance while signed in to the invited account.');

        $this->post('/membership-invitations/accept/authenticated')
            ->assertRedirect('/membership-invitations/accepted');

        $this->assertSame('active', $membership->refresh()->status);
        $this->assertNotNull($membership->joined_at);
        $this->assertDatabaseHas('customer_membership_lifecycle_events', [
            'customer_membership_id' => $membership->getKey(),
            'actor_user_id' => $invitee->getKey(),
            'from_status' => 'invited',
            'to_status' => 'active',
            'reason' => 'Invitation accepted by the invited user.',
        ]);

        $this->post('/membership-invitations/accept/authenticated')
            ->assertStatus(410);
    }

    public function test_expired_invitation_cannot_be_prepared_or_accepted(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $email = mb_strtolower('expired-invitee-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $email,
                'role_slug' => 'support_agent',
                'reason' => 'Invitation expiry test.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $token = $this->sentInvitationTokens($email)[0];

        DB::table('vendor_membership_invitations')
            ->where('vendor_membership_id', $membership->getKey())
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/membership-invitations/prepare', ['token' => $token])
            ->assertStatus(410);
        $this->assertSame('invited', $membership->refresh()->status);
    }

    public function test_resending_an_invitation_rotates_the_token_and_invalidates_the_old_one(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $email = mb_strtolower('resend-invitee-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $email,
                'role_slug' => 'support_agent',
                'reason' => 'Initial invitation.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $tokens = $this->sentInvitationTokens($email);
        $this->assertCount(1, $tokens);
        $oldToken = $tokens[0];

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/invitation/resend', [
                'reason' => 'Recipient requested a fresh invitation link.',
            ])
            ->assertOk();

        $tokens = $this->sentInvitationTokens($email);
        $this->assertCount(2, $tokens);
        $newToken = $tokens[1];
        $this->assertNotSame($oldToken, $newToken);

        Auth::logout();

        $this->postJson('/membership-invitations/prepare', ['token' => $oldToken])
            ->assertStatus(410);
        $this->postJson('/membership-invitations/prepare', ['token' => $newToken])
            ->assertOk()
            ->assertJsonPath('next', 'account_setup');
    }

    public function test_revoking_invited_membership_revokes_its_pending_invitation_token(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $email = mb_strtolower('revoke-invitee-'.str()->random(8).'@example.test');

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $email,
                'role_slug' => 'support_agent',
                'reason' => 'Invitation before withdrawal.',
            ])
            ->assertCreated();

        $membership = VendorMembership::query()->whereKey((string) $response->json('data.membership_id'))->firstOrFail();
        $token = $this->sentInvitationTokens($email)[0];

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/revoke', [
                'reason' => 'Invitation withdrawn.',
            ])
            ->assertOk();

        $revokedInvitation = DB::table('vendor_membership_invitations')
            ->where('vendor_membership_id', $membership->getKey())
            ->where('token_hash', hash('sha256', $token))
            ->first();
        $this->assertNotNull($revokedInvitation?->revoked_at);
        $this->postJson('/membership-invitations/prepare', ['token' => $token])
            ->assertStatus(410);
    }

    public function test_invitation_rejects_vendor_roles_that_require_maker_checker(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $email = mb_strtolower('privileged-invitee-'.str()->random(8).'@example.test');

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/invitations', [
                'email' => $email,
                'role_slug' => 'finance_admin',
                'reason' => 'Privileged role must use maker-checker.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => $email]);
        Mail::assertNothingSent();
    }

    public function test_vendor_admin_can_reinstate_a_revoked_member_with_a_new_invitation_and_role_history(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createVerifiedUser('vendor-reinstatement');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $invitee->getKey(),
            'status' => 'active',
            'joined_at' => now()->subDays(10),
        ]);
        $oldRole = $this->role('vendor', 'security_auditor');
        $membership->roles()->attach($oldRole);
        $oldToken = str()->random(64);
        $oldInvitation = VendorMembershipInvitation::query()->create([
            'vendor_membership_id' => $membership->getKey(),
            'invited_by_user_id' => $actor->getKey(),
            'invited_email' => $invitee->email,
            'reason' => 'Original access grant.',
            'token_hash' => hash('sha256', $oldToken),
            'expires_at' => now()->addDay(),
            'sent_at' => now(),
            'delivery_status' => 'sent',
        ]);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/revoke', [
                'reason' => 'Previous access was removed.',
            ])
            ->assertOk();

        $this->assertNotNull($oldInvitation->refresh()->revoked_at);

        $staleInvitation = VendorMembershipInvitation::query()->create([
            'vendor_membership_id' => $membership->getKey(),
            'invited_by_user_id' => $actor->getKey(),
            'invited_email' => $invitee->email,
            'reason' => 'Legacy pending invitation requiring defensive invalidation.',
            'token_hash' => hash('sha256', str()->random(64)),
            'expires_at' => now()->addDay(),
            'delivery_status' => 'sent',
        ]);

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'support_agent',
                'reason' => 'Restore access after review.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');

        $this->assertNotNull($staleInvitation->refresh()->revoked_at);
        $this->assertSame('invited', $membership->refresh()->status);
        $this->assertNull($membership->joined_at);
        $this->assertSame($actor->getKey(), $membership->invited_by);
        $this->assertFalse($membership->roles()->whereKey($oldRole->getKey())->exists());
        $this->assertTrue($membership->roles()->where('slug', 'support_agent')->exists());
        $this->assertDatabaseHas('vendor_membership_lifecycle_events', [
            'vendor_membership_id' => $membership->getKey(),
            'actor_user_id' => $actor->getKey(),
            'from_status' => 'revoked',
            'to_status' => 'invited',
            'reason' => 'Restore access after review.',
        ]);
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $membership->getKey(),
            'role_id' => $oldRole->getKey(),
            'action' => 'revoked',
        ]);
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $membership->getKey(),
            'role_id' => $this->role('vendor', 'support_agent')->getKey(),
            'action' => 'assigned',
        ]);

        $tokens = $this->sentInvitationTokens($invitee->email);
        $this->assertCount(1, $tokens);
        $newToken = $tokens[0];
        $this->assertNotSame($oldToken, $newToken);

        $newInvitation = VendorMembershipInvitation::query()
            ->where('vendor_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame(hash('sha256', $newToken), $newInvitation->token_hash);
        $this->assertNotSame($oldInvitation->token_hash, $newInvitation->token_hash);
        $this->assertSame('sent', $newInvitation->delivery_status);
        $this->assertSame($membership->getKey(), (string) $response->json('data.membership_id'));
    }

    public function test_customer_owner_can_reinstate_a_revoked_member_with_a_new_invitation_and_role_history(): void
    {
        Mail::fake();
        [$actor, $organization] = $this->customerActor();
        $invitee = $this->createVerifiedUser('customer-reinstatement');
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $invitee->getKey(),
            'status' => 'active',
            'joined_at' => now()->subDays(10),
        ]);
        $oldRole = $this->role('customer', 'customer_technical_admin');
        $membership->roles()->attach($oldRole);
        $oldToken = str()->random(64);
        $oldInvitation = CustomerMembershipInvitation::query()->create([
            'customer_membership_id' => $membership->getKey(),
            'invited_by_user_id' => $actor->getKey(),
            'invited_email' => $invitee->email,
            'reason' => 'Original customer access grant.',
            'token_hash' => hash('sha256', $oldToken),
            'expires_at' => now()->addDay(),
            'sent_at' => now(),
            'delivery_status' => 'sent',
        ]);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$membership->getKey().'/revoke', [
                'reason' => 'Previous customer access was removed.',
            ])
            ->assertOk();

        $this->assertNotNull($oldInvitation->refresh()->revoked_at);

        $staleInvitation = CustomerMembershipInvitation::query()->create([
            'customer_membership_id' => $membership->getKey(),
            'invited_by_user_id' => $actor->getKey(),
            'invited_email' => $invitee->email,
            'reason' => 'Legacy pending invitation requiring defensive invalidation.',
            'token_hash' => hash('sha256', str()->random(64)),
            'expires_at' => now()->addDay(),
            'delivery_status' => 'sent',
        ]);

        $response = $this->authenticatedJson($actor)
            ->postJson('/api/v1/customer-organizations/'.$organization->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'customer_member',
                'reason' => 'Restore customer access after review.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');

        $this->assertNotNull($staleInvitation->refresh()->revoked_at);
        $this->assertSame('invited', $membership->refresh()->status);
        $this->assertNull($membership->joined_at);
        $this->assertSame($actor->getKey(), $membership->invited_by);
        $this->assertFalse($membership->roles()->whereKey($oldRole->getKey())->exists());
        $this->assertTrue($membership->roles()->where('slug', 'customer_member')->exists());
        $this->assertDatabaseHas('customer_membership_lifecycle_events', [
            'customer_membership_id' => $membership->getKey(),
            'actor_user_id' => $actor->getKey(),
            'from_status' => 'revoked',
            'to_status' => 'invited',
            'reason' => 'Restore customer access after review.',
        ]);
        $this->assertDatabaseHas('customer_membership_role_events', [
            'customer_membership_id' => $membership->getKey(),
            'role_id' => $oldRole->getKey(),
            'action' => 'revoked',
        ]);
        $this->assertDatabaseHas('customer_membership_role_events', [
            'customer_membership_id' => $membership->getKey(),
            'role_id' => $this->role('customer', 'customer_member')->getKey(),
            'action' => 'assigned',
        ]);

        $tokens = $this->sentInvitationTokens($invitee->email);
        $this->assertCount(1, $tokens);
        $newToken = $tokens[0];
        $this->assertNotSame($oldToken, $newToken);

        $newInvitation = CustomerMembershipInvitation::query()
            ->where('customer_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame(hash('sha256', $newToken), $newInvitation->token_hash);
        $this->assertNotSame($oldInvitation->token_hash, $newInvitation->token_hash);
        $this->assertSame('sent', $newInvitation->delivery_status);
        $this->assertSame($membership->getKey(), (string) $response->json('data.membership_id'));
    }

    public function test_vendor_super_admin_can_clear_a_protected_role_during_reinstatement(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createVerifiedUser('protected-role-reinstatement');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $invitee->getKey(),
            'status' => 'revoked',
            'joined_at' => null,
        ]);
        $protectedRole = $this->role('vendor', 'super_admin');
        $membership->roles()->attach($protectedRole);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'support_agent',
                'reason' => 'Restore with least-privilege after review.',
            ])
            ->assertCreated();

        $this->assertFalse($membership->refresh()->roles()->whereKey($protectedRole->getKey())->exists());
        $this->assertTrue($membership->roles()->where('slug', 'support_agent')->exists());
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $membership->getKey(),
            'role_id' => $protectedRole->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => 'revoked',
        ]);
    }

    public function test_vendor_admin_cannot_clear_a_protected_role_during_reinstatement(): void
    {
        Mail::fake();
        [$actor, $vendor] = $this->vendorActor();
        $actorMembership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->firstOrFail();
        $actorMembership->roles()->detach($this->role('vendor', 'super_admin'));
        $actorMembership->roles()->attach($this->role('vendor', 'vendor_admin'));

        $invitee = $this->createVerifiedUser('protected-role-denied');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $invitee->getKey(),
            'status' => 'revoked',
            'joined_at' => null,
        ]);
        $protectedRole = $this->role('vendor', 'super_admin');
        $membership->roles()->attach($protectedRole);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'support_agent',
                'reason' => 'A vendor administrator cannot remove protected roles.',
            ])
            ->assertForbidden();

        $this->assertSame('revoked', $membership->refresh()->status);
        $this->assertTrue($membership->roles()->whereKey($protectedRole->getKey())->exists());
        $this->assertDatabaseMissing('vendor_membership_invitations', ['vendor_membership_id' => $membership->getKey()]);
        Mail::assertNothingSent();
    }

    public function test_reinstatement_rejects_an_inactive_target_account_without_mutation(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('inactive-reinstatement');
        $invitee->forceFill(['status' => 'suspended'])->save();
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $invitee->getKey(),
            'status' => 'revoked',
            'joined_at' => null,
        ]);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'support_agent',
                'reason' => 'Inactive users cannot be reinstated.',
            ])
            ->assertForbidden();

        $this->assertSame('revoked', $membership->refresh()->status);
        $this->assertDatabaseMissing('vendor_membership_invitations', ['vendor_membership_id' => $membership->getKey()]);
    }

    public function test_reinstatement_requires_fresh_mfa_step_up(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $invitee = $this->createUser('step-up-required');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $invitee->getKey(),
            'status' => 'revoked',
            'joined_at' => null,
        ]);

        $this->actingAs($actor, 'web')
            ->withSession([
                'auth_session_version' => (int) $actor->session_version,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'support_agent',
                'reason' => 'Fresh MFA step-up is mandatory.',
            ])
            ->assertStatus(423);

        $this->assertSame('revoked', $membership->refresh()->status);
    }

    public function test_reinstatement_rejects_a_membership_that_is_not_revoked(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $this->createUser('not-revoked')->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'support_agent',
                'reason' => 'Only revoked memberships may be reinstated.',
            ])
            ->assertConflict();

        $this->assertSame('active', $membership->refresh()->status);
    }

    public function test_reinstatement_rejects_vendor_roles_that_require_maker_checker(): void
    {
        [$actor, $vendor] = $this->vendorActor();
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $this->createUser('privileged-reinstatement')->getKey(),
            'status' => 'revoked',
            'joined_at' => null,
        ]);
        $this->authenticatedJson($actor)
            ->postJson('/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$membership->getKey().'/reinstate', [
                'role_slug' => 'finance_admin',
                'reason' => 'High-risk grants must use maker-checker.',
            ])
            ->assertForbidden();

        $this->assertSame('revoked', $membership->refresh()->status);
        $this->assertDatabaseMissing('vendor_membership_invitations', ['vendor_membership_id' => $membership->getKey()]);
    }

    /**
     * @return list<string>
     */
    private function sentInvitationTokens(string $email): array
    {
        $tokens = [];

        Mail::assertSent(MembershipInvitationMail::class, function (MembershipInvitationMail $mail) use ($email, &$tokens): bool {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $fragment = (string) parse_url($mail->acceptanceUrl, PHP_URL_FRAGMENT);
            $query = [];
            parse_str($fragment, $query);
            $token = $query['token'] ?? null;

            if (is_string($token)) {
                $tokens[] = $token;
            }

            return is_string($token) && strlen($token) === 64;
        });

        return $tokens;
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

    private function createVerifiedUser(string $label): User
    {
        $user = $this->createUser($label);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
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
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    /** @return array{User, VendorAccount} */
    private function vendorActor(): array
    {
        $actor = $this->createVerifiedUser('vendor-admin');
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
        $actor = $this->createVerifiedUser('customer-owner');
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
