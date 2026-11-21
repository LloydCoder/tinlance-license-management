<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Identity\SessionRevocationService;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\PasskeyUser as FortifyPasskeyUser;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

final class AuthenticationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled_and_required_authentication_routes_exist(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertTrue(Route::has('login'));
        $this->assertTrue(Route::has('passkey.login'));
        $this->assertTrue(Route::has('password.request'));
        $this->assertTrue(Route::has('verification.notice'));
        $this->assertTrue(Route::has('two-factor.login'));
    }

    public function test_fortify_guest_authentication_views_are_renderable(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/test-reset-token')->assertOk();
    }

    public function test_authentication_features_and_database_session_storage_are_configured(): void
    {
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->assertTrue(Features::enabled(Features::resetPasswords()));
        $this->assertTrue(Features::enabled(Features::emailVerification()));
        $this->assertTrue(Features::enabled(Features::twoFactorAuthentication()));
        $this->assertTrue(Features::enabled(Features::passkeys()));
        $this->assertSame('database', config('session.driver'));
        $this->assertTrue(config('session.encrypt'));
        $this->assertTrue(config('session.secure'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_user_supports_email_verification_totp_and_passkeys(): void
    {
        $user = $this->createUser();

        $this->assertInstanceOf(MustVerifyEmail::class, $user);
        $this->assertInstanceOf(FortifyPasskeyUser::class, $user);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $user->getPasskeyUserHandle());
        $this->assertNotSame($user->email, $user->getPasskeyUserHandle());
    }

    public function test_passkey_login_authorization_rejects_unverified_and_inactive_users(): void
    {
        $verified = $this->createUser();
        $verified->forceFill(['email_verified_at' => now()])->save();
        $passkey = $verified->passkeys()->create([
            'name' => 'Test passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlk',
            'credential' => ['publicKey' => 'test-public-key'],
        ]);

        $this->assertTrue(Passkeys::allowsLogin(request(), $passkey));

        $verified->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse(Passkeys::allowsLogin(request(), $passkey));

        $verified->forceFill(['status' => 'active', 'email_verified_at' => null])->save();
        $this->assertFalse(Passkeys::allowsLogin(request(), $passkey));
    }

    public function test_active_super_admin_without_a_confirmed_factor_is_redirected_to_mfa_enrollment(): void
    {
        $user = $this->createSuperAdmin();

        $response = $this->actingAs($user)
            ->withSession(['auth_session_version' => 0])
            ->get('/admin');

        $response->assertRedirect(route('security.mfa.enroll'));
    }

    public function test_confirmed_totp_allows_super_admin_access(): void
    {
        $user = $this->createSuperAdmin();
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-one'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->withSession(['auth_session_version' => 0])
            ->get('/admin')
            ->assertOk();
    }

    public function test_passkey_enrollment_allows_super_admin_access(): void
    {
        $user = $this->createSuperAdmin();
        $user->passkeys()->create([
            'name' => 'Primary passkey',
            'credential_id' => 'cHJpbWFyeS1wYXNza2V5LWlk',
            'credential' => ['publicKey' => 'test-public-key'],
        ]);

        $this->actingAs($user)
            ->withSession(['auth_session_version' => 0])
            ->get('/admin')
            ->assertOk();
    }

    public function test_stale_session_version_is_invalidated_before_admin_access(): void
    {
        $user = $this->createSuperAdmin();
        $user->forceFill([
            'session_version' => 2,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-one'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->withSession(['auth_session_version' => 1])
            ->get('/admin')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_session_revocation_increments_version_and_deletes_all_database_sessions(): void
    {
        $user = $this->createUser();

        DB::table('sessions')->insert([
            [
                'id' => str_repeat('a', 40),
                'user_id' => $user->getKey(),
                'ip_address' => '192.0.2.10',
                'user_agent' => 'TLMS test client one',
                'payload' => base64_encode('session-one'),
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => str_repeat('b', 40),
                'user_id' => $user->getKey(),
                'ip_address' => '192.0.2.11',
                'user_agent' => 'TLMS test client two',
                'payload' => base64_encode('session-two'),
                'last_activity' => now()->timestamp,
            ],
        ]);

        $revoked = app(SessionRevocationService::class)->revokeAll($user);

        $this->assertSame(2, $revoked);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->getKey()]);
    }

    public function test_authenticated_super_admin_security_views_render_with_recent_password_confirmation(): void
    {
        $user = $this->createSuperAdmin();
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-one'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->get('/admin/security/sessions')
            ->assertOk();

        $this->get('/security/mfa')->assertOk();
    }

    public function test_mfa_enrollment_requires_recent_password_confirmation(): void
    {
        $user = $this->createSuperAdmin();

        $this->actingAs($user)
            ->withSession(['auth_session_version' => 0])
            ->get('/security/mfa')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_inactive_users_cannot_authenticate_with_a_valid_password(): void
    {
        $user = $this->createUser();
        $user->forceFill(['status' => 'suspended'])->save();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'A-long-test-passphrase-123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'TLMS Test Administrator',
            'email' => 'administrator@example.test',
            'password' => 'A-long-test-passphrase-123',
            'status' => 'active',
        ]);
    }

    private function createSuperAdmin(): User
    {
        app(AuthorizationCatalogSeeder::class)->run();

        $user = $this->createUser();
        $user->forceFill(['email_verified_at' => now()])->save();

        $vendor = VendorAccount::query()->create([
            'legal_name' => 'TLMS Test Vendor Limited',
            'display_name' => 'TLMS Test Vendor',
            'status' => 'active',
            'default_currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
        ]);
        $role = Role::query()
            ->where('scope', 'vendor')
            ->where('slug', 'super_admin')
            ->firstOrFail();
        $membership->roles()->attach($role->getKey());

        return $user;
    }
}
