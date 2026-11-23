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
        Schema::create('product_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'product_versions_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'product_versions_vendor_fk')
                ->restrictOnDelete();
            $table->string('version', 64);
            $table->string('status', 16)->default('draft');
            $table->text('release_notes')->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->unsignedBigInteger('artifact_size_bytes')->nullable();
            $table->unsignedInteger('version_number')->default(1);
            $table->foreignUlid('created_by_user_id')
                ->constrained('users', 'id', 'product_versions_creator_fk')
                ->restrictOnDelete();
            $table->foreignUlid('updated_by_user_id')
                ->constrained('users', 'id', 'product_versions_updater_fk')
                ->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'version'], 'product_versions_product_version_unique');
            $table->index(['vendor_account_id', 'product_id', 'status'], 'product_versions_vendor_status_idx');
        });

        Schema::create('product_version_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')
                ->constrained('product_versions', 'id', 'product_version_events_version_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'product_version_events_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'product_version_events_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'product_version_events_actor_fk')
                ->restrictOnDelete();
            $table->string('event_type', 16);
            $table->string('reason', 500);
            $table->json('changed_fields');
            $table->char('change_digest', 64);
            $table->string('request_id', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_version_id', 'created_at'], 'product_version_events_timeline_idx');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('product_version_events');
        Schema::dropIfExists('product_versions');
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS product_versions_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_versions_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS product_version_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS product_version_events_prevent_delete');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_versions_guard_update
            BEFORE UPDATE ON product_versions
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Published product versions are immutable';
                END IF;

                IF NEW.product_id <> OLD.product_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.version <> OLD.version
                    OR NEW.created_by_user_id <> OLD.created_by_user_id
                    OR NEW.version_number <> OLD.version_number + 1 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Product version identity or sequence is immutable';
                END IF;

                IF NEW.status = 'published' THEN
                    IF NEW.published_at IS NULL
                        OR NOT (NEW.release_notes <=> OLD.release_notes)
                        OR NOT (NEW.artifact_sha256 <=> OLD.artifact_sha256)
                        OR NOT (NEW.artifact_size_bytes <=> OLD.artifact_size_bytes) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Publishing must freeze product version metadata';
                    END IF;
                ELSEIF NEW.status = 'draft' THEN
                    IF NEW.published_at IS NOT NULL
                        OR (
                            (NEW.release_notes <=> OLD.release_notes)
                            AND (NEW.artifact_sha256 <=> OLD.artifact_sha256)
                            AND (NEW.artifact_size_bytes <=> OLD.artifact_size_bytes)
                        ) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Draft product version updates require metadata changes';
                    END IF;
                ELSE
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Product version status transition is invalid';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_versions_prevent_delete
            BEFORE DELETE ON product_versions
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Product versions are retained and cannot be deleted'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_version_events_prevent_update
            BEFORE UPDATE ON product_version_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Product version events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER product_version_events_prevent_delete
            BEFORE DELETE ON product_version_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Product version events are immutable'
            SQL);
    }
};
