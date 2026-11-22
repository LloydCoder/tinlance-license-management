<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mfa_recovery_codes')) {
            Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
                $table->char('code_hash', 64);
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->unique(['user_id', 'code_hash'], 'mfa_recovery_codes_user_hash_unique');
                $table->index(['user_id', 'consumed_at', 'invalidated_at'], 'mfa_recovery_codes_user_active_idx');
            });
        }

        DB::table('users')
            ->select(['id', 'two_factor_recovery_codes'])
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_recovery_codes')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $plaintext = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_recovery_codes);
                    $codes = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

                    if (! is_array($codes) || ! array_is_list($codes)) {
                        throw new RuntimeException('Unable to migrate an existing MFA recovery-code set.');
                    }

                    $now = now();
                    $rows = [];

                    foreach ($codes as $code) {
                        if (! is_string($code) || trim($code) === '') {
                            throw new RuntimeException('Unable to migrate an invalid MFA recovery code.');
                        }

                        $rows[] = [
                            'id' => (string) Str::ulid(),
                            'user_id' => $user->id,
                            'code_hash' => hash('sha256', strtolower(trim($code))),
                            'consumed_at' => null,
                            'invalidated_at' => null,
                            'created_at' => $now,
                        ];
                    }

                    if ($rows !== []) {
                        DB::table('mfa_recovery_codes')->insertOrIgnore($rows);
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
                        ]);
                }
            });
    }

    public function down(): void
    {
        throw new RuntimeException('Hashed MFA recovery codes cannot be restored to the legacy encrypted format. Reissue codes and plan an explicit rollback before removing this table.');
    }
};
