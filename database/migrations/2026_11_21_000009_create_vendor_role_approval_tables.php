<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_account_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('target_vendor_membership_id')->constrained('vendor_memberships')->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained()->restrictOnDelete();
            $table->string('action', 64);
            $table->string('status', 16);
            $table->foreignUlid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(
                ['vendor_account_id', 'idempotency_key'],
                'approval_requests_vendor_idempotency_unique',
            );
            $table->index(
                ['vendor_account_id', 'status', 'expires_at'],
                'approval_requests_vendor_status_expiry_idx',
            );
        });

        Schema::create('approval_decisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('approval_request_id')->constrained('approval_requests')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->unique('approval_request_id', 'approval_decisions_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
    }
};
