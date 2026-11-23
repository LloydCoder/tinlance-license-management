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
        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('public_id')->unique();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'products_vendor_fk')
                ->restrictOnDelete();
            $table->string('slug', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('created_by_user_id')
                ->constrained('users', 'id', 'products_creator_fk')
                ->restrictOnDelete();
            $table->foreignUlid('updated_by_user_id')
                ->nullable()
                ->constrained('users', 'id', 'products_updater_fk')
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['vendor_account_id', 'slug'], 'products_vendor_slug_unique');
            $table->index(['vendor_account_id', 'status'], 'products_vendor_status_idx');
        });

        Schema::create('product_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'product_events_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'product_events_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'product_events_actor_fk')
                ->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('reason', 500);
            $table->json('changed_fields');
            $table->char('change_digest', 64);
            $table->string('request_id', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at'], 'product_events_timeline_idx');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('product_events');
        Schema::dropIfExists('products');
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS product_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_events_prevent_delete');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_events_prevent_update
            BEFORE UPDATE ON product_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Product events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_events_prevent_delete
            BEFORE DELETE ON product_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Product events are immutable'
            SQL);
    }
};
