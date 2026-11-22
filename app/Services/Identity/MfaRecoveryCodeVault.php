<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;

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
            $codes = $this->generateCodes();
            $now = now();

            DB::table('mfa_recovery_codes')
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now]);

            $rows = [];

            foreach ($codes as $code) {
                $rows[] = [
                    'id' => (string) str()->ulid(),
                    'user_id' => $lockedUser->getKey(),
                    'code_hash' => hash('sha256', $code),
                    'consumed_at' => null,
                    'invalidated_at' => null,
                    'created_at' => $now,
                ];
            }

            DB::table('mfa_recovery_codes')->insert($rows);

            $lockedUser->forceFill([
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
            ])->save();

            return $codes;
        }, 3);
    }

    public function invalidateAll(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            DB::table('mfa_recovery_codes')
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $lockedUser->forceFill([
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
            ])->save();
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
                ->whereNull('invalidated_at')
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
}
