<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Identity\MfaRecoveryCodeVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

final class MfaRecoveryCodeVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_recovery_codes_have_at_least_128_bits_and_only_sha256_digests_are_persisted(): void
    {
        $user = $this->createMfaUser();

        $codes = app(MfaRecoveryCodeVault::class)->replaceAll($user);

        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $code);
            $this->assertDatabaseHas('mfa_recovery_codes', [
                'user_id' => $user->getKey(),
                'code_hash' => hash('sha256', $code),
                'consumed_at' => null,
            ]);
        }

        $stored = DB::table('mfa_recovery_codes')->where('user_id', $user->getKey())->pluck('code_hash')->all();
        $this->assertCount(8, $stored);
        foreach ($codes as $code) {
            $this->assertNotContains($code, $stored);
        }

        $user->refresh();
        $this->assertSame('[]', Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes));
    }

    public function test_consuming_a_recovery_code_is_single_use_and_issues_a_replacement(): void
    {
        $user = $this->createMfaUser();
        $vault = app(MfaRecoveryCodeVault::class);
        $codes = $vault->replaceAll($user);

        $replacement = $vault->consumeAndReplace($user, strtoupper($codes[0]));

        $this->assertIsString($replacement);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $replacement);
        $this->assertNotSame($codes[0], $replacement);
        $this->assertDatabaseHas('mfa_recovery_codes', [
            'user_id' => $user->getKey(),
            'code_hash' => hash('sha256', $codes[0]),
        ]);
        $this->assertNotNull(
            DB::table('mfa_recovery_codes')
                ->where('user_id', $user->getKey())
                ->where('code_hash', hash('sha256', $codes[0]))
                ->value('consumed_at'),
        );
        $this->assertDatabaseHas('mfa_recovery_codes', [
            'user_id' => $user->getKey(),
            'code_hash' => hash('sha256', $replacement),
            'consumed_at' => null,
        ]);

        $this->assertNull($vault->consumeAndReplace($user, $codes[0]));
    }

    public function test_regeneration_invalidates_every_previous_code_and_replaces_the_set(): void
    {
        $user = $this->createMfaUser();
        $vault = app(MfaRecoveryCodeVault::class);
        $firstSet = $vault->replaceAll($user);
        $secondSet = $vault->replaceAll($user);

        $this->assertCount(8, $secondSet);
        foreach ($firstSet as $oldCode) {
            $this->assertNull($vault->consumeAndReplace($user, $oldCode));
        }
        foreach ($secondSet as $newCode) {
            $this->assertDatabaseHas('mfa_recovery_codes', [
                'user_id' => $user->getKey(),
                'code_hash' => hash('sha256', $newCode),
                'consumed_at' => null,
            ]);
        }
    }

    public function test_legacy_encrypted_fortify_codes_are_backfilled_to_hashes_idempotently(): void
    {
        $user = $this->createMfaUser();
        $legacyCodes = array_map(
            static fn (int $_index): string => str()->random(10).'-'.str()->random(10),
            range(1, 8),
        );

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($legacyCodes, JSON_THROW_ON_ERROR)),
        ])->save();

        $migration = require base_path('database/migrations/2026_11_22_000001_create_mfa_recovery_codes_table.php');
        $migration->up();
        $migration->up();

        foreach ($legacyCodes as $legacyCode) {
            $this->assertDatabaseHas('mfa_recovery_codes', [
                'user_id' => $user->getKey(),
                'code_hash' => hash('sha256', strtolower($legacyCode)),
                'consumed_at' => null,
            ]);
        }

        $this->assertSame(
            '[]',
            Fortify::currentEncrypter()->decrypt(
                (string) DB::table('users')->where('id', $user->getKey())->value('two_factor_recovery_codes'),
            ),
        );
        $this->assertSame(8, DB::table('mfa_recovery_codes')->where('user_id', $user->getKey())->count());
    }

    public function test_replacing_with_legacy_fortify_format_codes_stores_only_normalized_digests(): void
    {
        $user = $this->createMfaUser();
        $legacyCodes = array_map(
            static fn (): string => str()->random(10).'-'.str()->random(10),
            range(1, 8),
        );

        $normalized = app(MfaRecoveryCodeVault::class)->replaceWithCodes($user, $legacyCodes);

        $this->assertCount(8, $normalized);
        foreach ($legacyCodes as $legacyCode) {
            $this->assertDatabaseHas('mfa_recovery_codes', [
                'user_id' => $user->getKey(),
                'code_hash' => hash('sha256', strtolower($legacyCode)),
                'consumed_at' => null,
            ]);
        }
    }

    public function test_fortify_recovery_code_endpoint_never_discloses_saved_codes(): void
    {
        $user = $this->createMfaUser();
        app(MfaRecoveryCodeVault::class)->replaceAll($user);

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->get(route('two-factor.recovery-codes'))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_recovery_settings_page_exposes_a_working_totp_step_up_control_without_cache(): void
    {
        $user = $this->createMfaUser();

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->get(route('security.mfa.enroll'))
            ->assertOk()
            ->assertSee('data-step-up-totp', false)
            ->assertSee('build/membership-invitations.js', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_recovery_code_regeneration_requires_recent_password_and_fresh_mfa_step_up(): void
    {
        $user = $this->createMfaUser();
        $vault = app(MfaRecoveryCodeVault::class);
        $oldCodes = $vault->replaceAll($user);

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->postJson(route('two-factor.regenerate-recovery-codes'))
            ->assertStatus(423);

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => now()->timestamp,
                'auth.mfa_step_up_at' => now()->timestamp,
                'auth.mfa_step_up_method' => 'totp',
            ])
            ->postJson(route('two-factor.regenerate-recovery-codes'))
            ->assertOk();

        $this->assertTrue(session()->has('mfa.recovery_codes_once'));
        $newCodes = session('mfa.recovery_codes_once');
        $this->assertIsArray($newCodes);
        $this->assertCount(8, $newCodes);

        foreach ($oldCodes as $oldCode) {
            $this->assertNull($vault->consumeAndReplace($user, $oldCode));
        }

        foreach ($newCodes as $newCode) {
            $this->assertDatabaseHas('mfa_recovery_codes', [
                'user_id' => $user->getKey(),
                'code_hash' => hash('sha256', $newCode),
                'consumed_at' => null,
            ]);
        }
    }

    public function test_recovery_code_regeneration_is_denied_until_totp_enrollment_is_confirmed(): void
    {
        $user = $this->createMfaUser();
        $user->forceFill(['two_factor_confirmed_at' => null])->save();

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => now()->timestamp,
                'auth.mfa_step_up_at' => now()->timestamp,
                'auth.mfa_step_up_method' => 'totp',
            ])
            ->postJson(route('two-factor.regenerate-recovery-codes'))
            ->assertForbidden();
    }

    public function test_fortify_login_consumes_a_hashed_recovery_code_and_issues_one_replacement(): void
    {
        $password = str()->random(48);
        $user = $this->createMfaUser($password);
        $vault = app(MfaRecoveryCodeVault::class);
        $codes = $vault->replaceAll($user);
        $usedCode = $codes[0];

        $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertRedirect(route('two-factor.login'));

        $this->post('/two-factor-challenge', [
            'recovery_code' => $usedCode,
        ])->assertRedirect('/workspace');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(
            DB::table('mfa_recovery_codes')
                ->where('user_id', $user->getKey())
                ->where('code_hash', hash('sha256', $usedCode))
                ->value('consumed_at'),
        );
        $this->assertTrue(session()->has('mfa.recovery_code_replacement_once'));

        $replacement = session('mfa.recovery_code_replacement_once');
        $this->assertIsString($replacement);
        $this->assertDatabaseHas('mfa_recovery_codes', [
            'user_id' => $user->getKey(),
            'code_hash' => hash('sha256', $replacement),
            'consumed_at' => null,
        ]);
    }

    public function test_challenge_rejects_an_account_deactivated_after_password_authentication_without_consuming_its_code(): void
    {
        $password = str()->random(48);
        $user = $this->createMfaUser($password);
        $codes = app(MfaRecoveryCodeVault::class)->replaceAll($user);

        $this->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertRedirect(route('two-factor.login'));

        $user->forceFill(['status' => 'suspended'])->save();

        $this->post('/two-factor-challenge', [
            'recovery_code' => $codes[0],
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        $this->assertNull(
            DB::table('mfa_recovery_codes')
                ->where('user_id', $user->getKey())
                ->where('code_hash', hash('sha256', $codes[0]))
                ->value('consumed_at'),
        );
    }

    public function test_enabling_totp_persists_only_hashed_recovery_codes(): void
    {
        $user = User::query()->create([
            'name' => 'MFA Enable Test User',
            'email' => 'mfa-enable-'.str()->random(12).'@example.test',
            'password' => str()->random(48),
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => 0,
                'auth.password_confirmed_at' => now()->timestamp,
            ])
            ->post(route('two-factor.enable'))
            ->assertRedirect();

        $this->assertSame(8, DB::table('mfa_recovery_codes')->where('user_id', $user->getKey())->whereNull('consumed_at')->count());
        $this->assertSame('[]', Fortify::currentEncrypter()->decrypt(
            (string) DB::table('users')->where('id', $user->getKey())->value('two_factor_recovery_codes'),
        ));
        $this->assertTrue(session()->has('mfa.recovery_codes_once'));
    }

    private function createMfaUser(?string $password = null): User
    {
        $user = User::query()->create([
            'name' => 'MFA Recovery Test User',
            'email' => 'mfa-recovery-'.str()->random(12).'@example.test',
            'password' => $password ?? str()->random(48),
            'status' => 'active',
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }
}
