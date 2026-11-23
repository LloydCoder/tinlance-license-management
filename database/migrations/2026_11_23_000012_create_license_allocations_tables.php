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
        Schema::create('license_allocations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('license_id')
                ->constrained('licenses', 'id', 'license_allocations_license_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'license_allocations_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'license_allocations_product_fk')
                ->restrictOnDelete();
            $table->string('allocation_type', 16);
            $table->char('subject_hash', 64);
            $table->string('fingerprint_key_id', 64);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('created_by_user_id')
                ->constrained('users', 'id', 'license_allocations_creator_fk')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['license_id', 'allocation_type', 'subject_hash'],
                'license_allocations_subject_unique',
            );
            $table->index(
                ['license_id', 'allocation_type', 'status'],
                'license_allocations_capacity_idx',
            );
            $table->index(
                ['vendor_account_id', 'created_at'],
                'license_allocations_vendor_created_idx',
            );
        });

        Schema::create('license_allocation_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('allocation_id')
                ->constrained('license_allocations', 'id', 'license_allocation_events_allocation_fk')
                ->restrictOnDelete();
            $table->foreignUlid('license_id')
                ->constrained('licenses', 'id', 'license_allocation_events_license_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'license_allocation_events_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'license_allocation_events_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'license_allocation_events_actor_fk')
                ->restrictOnDelete();
            $table->string('event_type', 16);
            $table->string('reason', 500);
            $table->string('request_id', 128);
            $table->char('payload_sha256', 64);
            $table->json('event_payload');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['vendor_account_id', 'request_id'], 'license_allocation_events_request_unique');
            $table->index(['license_id', 'created_at'], 'license_allocation_events_timeline_idx');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('license_allocation_events');
        Schema::dropIfExists('license_allocations');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocations_guard_insert
            BEFORE INSERT ON license_allocations
            FOR EACH ROW
            BEGIN
                IF NEW.status <> 'active'
                    OR NEW.version <> 1
                    OR NEW.allocation_type NOT IN ('site', 'seat', 'user')
                    OR CHAR_LENGTH(NEW.subject_hash) <> 64
                    OR NEW.subject_hash NOT REGEXP '^[a-f0-9]{64}
                        SELECT 1
                        FROM licenses
                        INNER JOIN plan_versions
                            ON plan_versions.id = licenses.plan_version_id
                            AND plan_versions.vendor_account_id = licenses.vendor_account_id
                            AND plan_versions.product_id = licenses.product_id
                            AND plan_versions.status = 'published'
                        WHERE licenses.id = NEW.license_id
                            AND licenses.vendor_account_id = NEW.vendor_account_id
                            AND licenses.product_id = NEW.product_id
                            AND licenses.status = 'active'
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence allocations require an active matching licence and published plan';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocations_guard_update
            BEFORE UPDATE ON license_allocations
            FOR EACH ROW
            BEGIN
                IF NEW.license_id <> OLD.license_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.product_id <> OLD.product_id
                    OR NEW.allocation_type <> OLD.allocation_type
                    OR NEW.subject_hash <> OLD.subject_hash
                    OR NEW.fingerprint_key_id <> OLD.fingerprint_key_id
                    OR NEW.created_by_user_id <> OLD.created_by_user_id
                    OR NEW.version <> OLD.version + 1
                    OR NOT (
                        (OLD.status = 'active' AND NEW.status = 'released')
                        OR (OLD.status = 'released' AND NEW.status = 'active')
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence allocation identity is immutable and state changes must be versioned';
                END IF;

                IF NEW.status = 'active' AND NOT EXISTS (
                    SELECT 1 FROM licenses
                    WHERE id = NEW.license_id
                        AND vendor_account_id = NEW.vendor_account_id
                        AND product_id = NEW.product_id
                        AND status = 'active'
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Only active licences may hold active allocations';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocations_prevent_delete
            BEFORE DELETE ON license_allocations
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence allocations must be released, not deleted'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocation_events_guard_insert
            BEFORE INSERT ON license_allocation_events
            FOR EACH ROW
            BEGIN
                IF NEW.event_type NOT IN ('allocated', 'released')
                    OR NOT EXISTS (
                        SELECT 1
                        FROM license_allocations
                        WHERE id = NEW.allocation_id
                            AND ((NEW.event_type = 'allocated' AND status = 'active')
                                OR (NEW.event_type = 'released' AND status = 'released'))
                            AND license_id = NEW.license_id
                            AND vendor_account_id = NEW.vendor_account_id
                            AND product_id = NEW.product_id
                    )
                    OR NOT EXISTS (
                        SELECT 1 FROM vendor_memberships
                        WHERE vendor_account_id = NEW.vendor_account_id
                            AND user_id = NEW.actor_user_id
                            AND status = 'active'
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence allocation events require a matching allocation and active vendor actor';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocation_events_prevent_update
            BEFORE UPDATE ON license_allocation_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence allocation events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocation_events_prevent_delete
            BEFORE DELETE ON license_allocation_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence allocation events are immutable'
            SQL);
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS license_allocation_events_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocation_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocation_events_guard_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_guard_insert');
    }
};

                    OR CHAR_LENGTH(NEW.fingerprint_key_id) < 1
                    OR CHAR_LENGTH(NEW.fingerprint_key_id) > 64
                    OR NOT EXISTS (
                        SELECT 1
                        FROM licenses
                        INNER JOIN plan_versions
                            ON plan_versions.id = licenses.plan_version_id
                            AND plan_versions.vendor_account_id = licenses.vendor_account_id
                            AND plan_versions.product_id = licenses.product_id
                            AND plan_versions.status = 'published'
                        WHERE licenses.id = NEW.license_id
                            AND licenses.vendor_account_id = NEW.vendor_account_id
                            AND licenses.product_id = NEW.product_id
                            AND licenses.status = 'active'
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence allocations require an active matching licence and published plan';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocations_guard_update
            BEFORE UPDATE ON license_allocations
            FOR EACH ROW
            BEGIN
                IF NEW.license_id <> OLD.license_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.product_id <> OLD.product_id
                    OR NEW.allocation_type <> OLD.allocation_type
                    OR NEW.subject_hash <> OLD.subject_hash
                    OR NEW.fingerprint_key_id <> OLD.fingerprint_key_id
                    OR NEW.created_by_user_id <> OLD.created_by_user_id
                    OR NEW.version <> OLD.version + 1
                    OR NOT (
                        (OLD.status = 'active' AND NEW.status = 'released')
                        OR (OLD.status = 'released' AND NEW.status = 'active')
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence allocation identity is immutable and state changes must be versioned';
                END IF;

                IF NEW.status = 'active' AND NOT EXISTS (
                    SELECT 1 FROM licenses
                    WHERE id = NEW.license_id
                        AND vendor_account_id = NEW.vendor_account_id
                        AND product_id = NEW.product_id
                        AND status = 'active'
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Only active licences may hold active allocations';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocations_prevent_delete
            BEFORE DELETE ON license_allocations
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence allocations must be released, not deleted'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocation_events_guard_insert
            BEFORE INSERT ON license_allocation_events
            FOR EACH ROW
            BEGIN
                IF NEW.event_type NOT IN ('allocated', 'released')
                    OR NOT EXISTS (
                        SELECT 1
                        FROM license_allocations
                        WHERE id = NEW.allocation_id
                            AND license_id = NEW.license_id
                            AND vendor_account_id = NEW.vendor_account_id
                            AND product_id = NEW.product_id
                    )
                    OR NOT EXISTS (
                        SELECT 1 FROM vendor_memberships
                        WHERE vendor_account_id = NEW.vendor_account_id
                            AND user_id = NEW.actor_user_id
                            AND status = 'active'
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence allocation events require a matching allocation and active vendor actor';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocation_events_prevent_update
            BEFORE UPDATE ON license_allocation_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence allocation events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocation_events_prevent_delete
            BEFORE DELETE ON license_allocation_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence allocation events are immutable'
            SQL);
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS license_allocation_events_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocation_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocation_events_guard_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_guard_insert');
    }
};
