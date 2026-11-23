<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_issuance_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'license_issuance_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'license_issuance_actor_fk')
                ->restrictOnDelete();
            $table->string('operation', 64);
            $table->char('idempotency_key_sha256', 64);
            $table->char('request_fingerprint_sha256', 64);
            $table->string('status', 16)->default('processing');
            $table->foreignUlid('license_id')->nullable()
                ->constrained('licenses', 'id', 'license_issuance_license_fk')
                ->restrictOnDelete();
            $table->text('response_ciphertext')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(
                ['vendor_account_id', 'operation', 'idempotency_key_sha256'],
                'license_issuance_idempotency_unique',
            );
            $table->index(['status', 'expires_at'], 'license_issuance_expiry_idx');
            $table->index(['vendor_account_id', 'created_at'], 'license_issuance_vendor_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_issuance_requests');
    }
};
