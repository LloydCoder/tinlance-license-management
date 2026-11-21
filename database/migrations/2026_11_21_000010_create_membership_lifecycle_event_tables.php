<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_membership_lifecycle_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_membership_id')->constrained('vendor_memberships', 'id', 'vendor_lifecycle_membership_fk')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users', 'id', 'vendor_lifecycle_actor_fk')->restrictOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(
                ['vendor_membership_id', 'created_at'],
                'vendor_membership_lifecycle_membership_created_idx',
            );
        });

        Schema::create('customer_membership_lifecycle_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_membership_id')->constrained('customer_memberships', 'id', 'customer_lifecycle_membership_fk')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users', 'id', 'customer_lifecycle_actor_fk')->restrictOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(
                ['customer_membership_id', 'created_at'],
                'customer_membership_lifecycle_membership_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_membership_lifecycle_events');
        Schema::dropIfExists('vendor_membership_lifecycle_events');
    }
};
