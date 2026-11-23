<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'product_assignments_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'product_assignments_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_membership_id')
                ->constrained('vendor_memberships', 'id', 'product_assignments_membership_fk')
                ->restrictOnDelete();
            $table->foreignUlid('assigned_by_user_id')
                ->constrained('users', 'id', 'product_assignments_actor_fk')
                ->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'vendor_membership_id'], 'product_assignments_target_unique');
            $table->index(['vendor_account_id', 'vendor_membership_id', 'revoked_at'], 'product_assignments_scope_idx');
        });

        Schema::create('product_assignment_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('assignment_id')
                ->constrained('product_assignments', 'id', 'product_assignment_events_assignment_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'product_assignment_events_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'product_assignment_events_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'product_assignment_events_actor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('target_vendor_membership_id')
                ->constrained('vendor_memberships', 'id', 'product_assignment_events_target_fk')
                ->restrictOnDelete();
            $table->string('event_type', 16);
            $table->string('reason', 500);
            $table->string('request_id', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at'], 'product_assignment_events_timeline_idx');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('product_assignment_events');
        Schema::dropIfExists('product_assignments');
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS product_assignment_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_assignment_events_prevent_delete');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_assignment_events_prevent_update
            BEFORE UPDATE ON product_assignment_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Product assignment events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_assignment_events_prevent_delete
            BEFORE DELETE ON product_assignment_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Product assignment events are immutable'
            SQL);
    }
};
