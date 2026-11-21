<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_membership_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_membership_id')->constrained('vendor_memberships')->restrictOnDelete();
            $table->foreignUlid('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('invited_email', 254);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('delivery_status', 16)->default('pending');
            $table->timestamps();
            $table->index(['vendor_membership_id', 'expires_at'], 'vendor_invites_membership_expiry_idx');
        });

        Schema::create('customer_membership_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_membership_id')->constrained('customer_memberships')->restrictOnDelete();
            $table->foreignUlid('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('invited_email', 254);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('delivery_status', 16)->default('pending');
            $table->timestamps();
            $table->index(['customer_membership_id', 'expires_at'], 'customer_invites_membership_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_membership_invitations');
        Schema::dropIfExists('vendor_membership_invitations');
    }
};
