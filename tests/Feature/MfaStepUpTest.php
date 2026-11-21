<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Tests\TestCase;

final class MfaStepUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_valid_totp_step_up_sets_five_minute_session_assurance_and_audit_event(): void
    {
        $user = $this->createUserWithTotp();

        $this->mock(TwoFactorAuthenticationProvider::class)
            ->shouldReceive('verify')
            ->once()
            ->with('JBSWY3DPEHPK3PXP', '123456')
            ->andReturn(true);

        $this->authenticatedJson($user)
            ->postJson('/api/v1/security/step-up/totp', ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.method', 'totp')
            ->assertJsonPath('data.expires_in', 300)
            ->assertJsonStructure(['request_id', 'data' => ['method', 'stepped_up_at', 'expires_at', 'expires_in']]);

        $this->assertIsInt(session('auth.mfa_step_up_at'));
        $this->assertSame('totp', session('auth.mfa_step_up_method'));
        $this->assertDatabaseHas('mfa_step_up_events', [
            'user_id' => $user->getKey(),
            'method' => 'totp',
            'outcome' => 'succeeded',
        ]);
    }

    public function test_invalid_totp_step_up_is_rejected_and_failure_is_audited_without_session_assurance(): void
    {
        $user = $this->createUserWithTotp();

        $this->mock(TwoFactorAuthenticationProvider::class)
            ->shouldReceive('verify')
            ->once()
            ->with('JBSWY3DPEHPK3PXP', '000000')
            ->andReturn(false);

        $this->authenticatedJson($user)
            ->postJson('/api/v1/security/step-up/totp', ['code' => '000000'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertNull(session('auth.mfa_step_up_at'));
        $this->assertDatabaseHas('mfa_step_up_events', [
            'user_id' => $user->getKey(),
            'method' => 'totp',
            'outcome' => 'failed',
        ]);
    }

    public function test_totp_step_up_is_unavailable_when_user_has_only_a_passkey(): void
    {
        $user = $this->createUserWithPasskey();

        $this->authenticatedJson($user)
            ->postJson('/api/v1/security/step-up/totp', ['code' => '123456'])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'TOTP_NOT_ENROLLED');
    }

    public function test_step_up_requires_recent_password_confirmation_before_totp_challenge(): void
    {
        $user = $this->createUserWithTotp();

        $this->actingAs($user, 'web')
            ->withSession(['auth_session_version' => $this->sessionVersion($user)])
            ->postJson('/api/v1/security/step-up/totp', ['code' => '123456'])
            ->assertStatus(423)
            ->assertJsonPath('code', 'PASSWORD_CONFIRMATION_REQUIRED');
    }

    public function test_totp_step_up_requires_a_six_digit_code(): void
    {
        $user = $this->createUserWithTotp();

        $this->authenticatedJson($user)
            ->postJson('/api/v1/security/step-up/totp', ['code' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_fortify_totp_provider_rejects_reuse_of_an_accepted_code(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $provider = app(TwoFactorAuthenticationProvider::class);

        $this->assertTrue($provider->verify($secret, $code));
        $this->assertFalse($provider->verify($secret, $code));
    }

    public function test_mfa_step_up_audit_events_are_immutable(): void
    {
        $user = $this->createUserWithTotp();
        $event = MfaStepUpEvent::query()->create([
            'user_id' => $user->getKey(),
            'method' => 'totp',
            'outcome' => 'succeeded',
            'request_id' => 'step-up-immutable-test',
            'created_at' => now(),
        ]);

        $this->expectException(LogicException::class);

        $event->update(['outcome' => 'failed']);
    }

    private function authenticatedJson(User $user): static
    {
        return $this->actingAs($user, 'web')->withSession([
            'auth_session_version' => $this->sessionVersion($user),
            'auth.password_confirmed_at' => time(),
        ]);
    }

    private function sessionVersion(User $user): int
    {
        return (int) User::query()->whereKey($user->getKey())->value('session_version');
    }

    private function createUserWithTotp(): User
    {
        $user = User::query()->create([
            'name' => 'Step Up TOTP User',
            'email' => 'step-up-totp-'.str()->random(12).'@example.test',
            'password' => str()->random(32),
            'status' => 'active',
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['unused-recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    private function createUserWithPasskey(): User
    {
        $user = User::query()->create([
            'name' => 'Step Up Passkey User',
            'email' => 'step-up-passkey-'.str()->random(12).'@example.test',
            'password' => str()->random(32),
            'status' => 'active',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->passkeys()->create([
            'name' => 'Step-up passkey',
            'credential_id' => 'step-up-credential-'.str()->random(10),
            'credential' => ['publicKey' => 'test-public-key'],
        ]);

        return $user->refresh();
    }
}
