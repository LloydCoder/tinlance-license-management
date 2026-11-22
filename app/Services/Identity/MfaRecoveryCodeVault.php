<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use RuntimeException;

final class MfaRecoveryCodeVault
{
    private const CODE_COUNT = 8;

    /**
     * @return list<string>
     */
    public function replaceAll(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return $this->replaceWithCodesLocked($lockedUser, $this->generateCodes());
        }, 3);
    }

    /**
     * Import a newly generated or legacy set while ensuring only digests remain persisted.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function replaceWithCodes(User $user, array $codes): array
    {
        return DB::transaction(function () use ($user, $codes): array {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            return $this->replaceWithCodesLocked($lockedUser, $codes);
        }, 3);
    }

    /**
     * Atomically consume a saved code and issue one replacement.
     */
    public function consumeAndReplace(User $user, string $code): ?string
    {
        $normalized = strtolower(trim($code));

        if ($normalized === '' || strlen($normalized) > 128) {
            return null;
        }

        return DB::transaction(function () use ($user, $normalized): ?string {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $lockedUser instanceof User) {
                return null;
            }

            $codeHash = hash('sha256', $normalized);
            $stored = DB::table('mfa_recovery_codes')
                ->where('user_id', $lockedUser->getKey())
                ->where('code_hash', $codeHash)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->lockForUpdate()
                ->first();

            if ($stored === null) {
                return null;
            }

            $consumed = DB::table('mfa_recovery_codes')
                ->where('id', $stored->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            if ($consumed !== 1) {
                return null;
            }

            $replacement = $this->generateCode();

            DB::table('mfa_recovery_codes')->insert([
                'id' => (string) str()->ulid(),
                'user_id' => $lockedUser->getKey(),
                'code_hash' => hash('sha256', $replacement),
                'consumed_at' => null,
                'invalidated_at' => null,
                'created_at' => now(),
            ]);

            return $replacement;
        }, 3);
    }

    /**
     * @return list<string>
     */
    private function generateCodes(): array
    {
        $codes = [];

        while (count($codes) < self::CODE_COUNT) {
            $code = $this->generateCode();
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    private function generateCode(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function replaceWithCodesLocked(User $user, array $codes): array
    {
        if (count($codes) !== self::CODE_COUNT) {
            throw new RuntimeException('An MFA recovery-code set must contain exactly eight codes.');
        }

        $normalizedCodes = [];

        foreach ($codes as $code) {
            if (! is_string($code) || trim($code) === '' || strlen($code) > 128) {
                throw new RuntimeException('An MFA recovery-code set contains an invalid code.');
            }

            $normalizedCodes[] = strtolower(trim($code));
        }

        if (count(array_unique($normalizedCodes)) !== self::CODE_COUNT) {
            throw new RuntimeException('An MFA recovery-code set must contain unique codes.');
        }

        $now = now();

        DB::table('mfa_recovery_codes')
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => $now]);

        $rows = [];

        foreach ($normalizedCodes as $code) {
            $rows[] = [
                'id' => (string) str()->ulid(),
                'user_id' => $user->getKey(),
                'code_hash' => hash('sha256', $code),
                'consumed_at' => null,
                'invalidated_at' => null,
                'created_at' => $now,
            ];
        }

        DB::table('mfa_recovery_codes')->insert($rows);

        $user->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
        ])->save();

        return $normalizedCodes;
    }
}
