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
        Schema::create('release_channels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'release_channels_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'release_channels_vendor_fk')
                ->restrictOnDelete();
            $table->string('slug', 32);
            $table->string('name', 100);
            $table->string('status', 16)->default('active');
            $table->foreignUlid('current_product_version_id')
                ->nullable()
                ->constrained('product_versions', 'id', 'release_channels_current_version_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->foreignUlid('created_by_user_id')
                ->constrained('users', 'id', 'release_channels_creator_fk')
                ->restrictOnDelete();
            $table->foreignUlid('updated_by_user_id')
                ->constrained('users', 'id', 'release_channels_updater_fk')
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'slug'], 'release_channels_product_slug_unique');
            $table->index(['vendor_account_id', 'product_id', 'status'], 'release_channels_vendor_scope_idx');
        });

        Schema::create('release_channel_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('release_channel_id')
                ->constrained('release_channels', 'id', 'release_channel_events_channel_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'release_channel_events_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'release_channel_events_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'release_channel_events_actor_fk')
                ->restrictOnDelete();
            $table->string('event_type', 16);
            $table->foreignUlid('previous_product_version_id')
                ->nullable()
                ->constrained('product_versions', 'id', 'release_channel_events_previous_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_version_id')
                ->nullable()
                ->constrained('product_versions', 'id', 'release_channel_events_version_fk')
                ->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('request_id', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['release_channel_id', 'created_at'], 'release_channel_events_timeline_idx');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('release_channel_events');
        Schema::dropIfExists('release_channels');
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS release_channels_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS release_channels_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS release_channel_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS release_channel_events_prevent_delete');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER release_channels_guard_update
            BEFORE UPDATE ON release_channels
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'active'
                    OR NEW.status <> 'active'
                    OR NEW.product_id <> OLD.product_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.slug <> OLD.slug
                    OR NEW.name <> OLD.name
                    OR NEW.created_by_user_id <> OLD.created_by_user_id
                    OR NEW.version_number <> OLD.version_number + 1
                    OR NEW.current_product_version_id <=> OLD.current_product_version_id THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Release channels may only advance through a versioned promotion';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER release_channels_prevent_delete
            BEFORE DELETE ON release_channels
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Release channels are retained and cannot be deleted'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER release_channel_events_prevent_update
            BEFORE UPDATE ON release_channel_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Release channel events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER release_channel_events_prevent_delete
            BEFORE DELETE ON release_channel_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Release channel events are immutable'
            SQL);
    }
};
