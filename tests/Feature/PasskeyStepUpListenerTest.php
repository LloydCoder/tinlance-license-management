<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Passkeys\Events\PasskeyVerified;
use Tests\TestCase;

final class PasskeyStepUpListenerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_authenticated_passkey_assertion_establishes_step_up_and_authorizes_membership_role_change(): void
    {
        $actor = $this->createUser('passkey-admin');
        $vendor = $this->createVendor();
        $actorMembership = $this->createMembership($vendor, $actor);
        $actorMembership->roles()->attach($this->role('super_admin'));

        $passkey = $actor->passkeys()->create([
            'name' => 'Privileged action passkey',
            'credential_id' => 'passkey-step-up-'.str()->random(12),
            'credential' => ['publicKey' => 'test-public-key'],
        ]);

        $target = $this->createMembership($vendor, $this->createUser('passkey-target'));
        $role = $this->role('vendor_developer');

        $this->actingAs($actor, 'web')->withSession([
            'auth_session_version' => $this->sessionVersion($actor),
            'auth.password_confirmed_at' => time(),
        ]);

        event(new PasskeyVerified($actor, $passkey));

        $this->assertSame('passkey', session('auth.mfa_step_up_method'));
        $this->assertIsInt(session('auth.mfa_step_up_at'));
        $this->assertDatabaseHas('mfa_step_up_events', [
            'user_id' => $actor->getKey(),
            'method' => 'passkey',
            'outcome' => 'succeeded',
        ]);

        $this->postJson(
            '/api/v1/vendor-accounts/'.$vendor->getKey().'/memberships/'.$target->getKey().'/roles/'.$role->getKey(),
            ['reason' => 'Passkey verified privileged role assignment.'],
        )->assertCreated();

        $this->assertTrue($target->refresh()->roles()->whereKey($role->getKey())->exists());
    }

    public function test_passkey_login_event_does_not_establish_step_up_for_a_guest(): void
    {
        $user = $this->createUser('passkey-guest');
        $passkey = $user->passkeys()->create([
            'name' => 'Login passkey',
            'credential_id' => 'passkey-guest-'.str()->random(12),
            'credential' => ['publicKey' => 'test-public-key'],
        ]);

        event(new PasskeyVerified($user, $passkey));

        $this->assertNull(session('auth.mfa_step_up_at'));
        $this->assertDatabaseMissing('mfa_step_up_events', [
            'user_id' => $user->getKey(),
            'method' => 'passkey',
            'outcome' => 'succeeded',
        ]);
    }

    private function createUser(string $suffix): User
    {
        $user = User::query()->create([
            'name' => 'Passkey Step-Up '.$suffix,
            'email' => 'passkey-step-up-'.$suffix.'-'.str()->random(8).'@example.test',
            'password' => str()->random(32),
            'status' => 'active',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
    }

    private function createVendor(): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => 'passkey-vendor-'.str()->random(12),
            'legal_name' => 'Passkey Step-Up Vendor Limited',
            'display_name' => 'Passkey Step-Up Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createMembership(VendorAccount $vendor, User $user): VendorMembership
    {
        return VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function role(string $slug): Role
    {
        return Role::query()->where('scope', 'vendor')->where('slug', $slug)->firstOrFail();
    }

    private function sessionVersion(User $user): int
    {
        return (int) User::query()->whereKey($user->getKey())->value('session_version');
    }
}
