<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Models\\User;
use App\\Services\\Identity\\MfaRecoveryCodeVault;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\DB;
use Laravel\\Fortify\\Fortify;
use Tests\\TestCase;

final class MfaRecoveryCodeVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_recovery_codes_have_at_least_128_bits_and_only_sha256_digests_are_persisted(): void
    {
        $user = $this->createMfaUser();

        $codes = app(MfaRecoveryCodeVault::class)->replaceAll($user);

        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/\\A[a-f0-9]{32}\\z/D', $code);
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
        $this->assertMatchesRegularExpression('/\\A[a-f0-9]{32}\\z/D', $replacement);
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

    private function createMfaUser(): User
    {
        $user = User::query()->create([
            'name' => 'MFA Recovery Test User',
            'email' => 'mfa-recovery-'.str()->random(12).'@example.test',
            'password' => str()->random(48),
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
