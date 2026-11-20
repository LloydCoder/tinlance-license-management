<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_membership_role_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_membership_id')->constrained('vendor_memberships')->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 16);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['vendor_membership_id', 'created_at'], 'vendor_role_events_membership_created_idx');
        });

        Schema::create('customer_membership_role_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_membership_id')->constrained('customer_memberships')->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 16);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_membership_id', 'created_at'], 'customer_role_events_membership_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_membership_role_events');
        Schema::dropIfExists('vendor_membership_role_events');
    }
};
