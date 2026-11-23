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
        Schema::create('license_usage_counters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('license_id')->constrained('licenses', 'id', 'license_usage_counters_license_fk')->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts', 'id', 'license_usage_counters_vendor_fk')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products', 'id', 'license_usage_counters_product_fk')->restrictOnDelete();
            $table->string('metric', 64);
            $table->string('period', 16);
            $table->timestamp('period_start');
            $table->timestamp('period_end')->nullable();
            $table->unsignedBigInteger('quantity')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['license_id', 'metric', 'period', 'period_start'], 'license_usage_counter_period_unique');
            $table->index(['vendor_account_id', 'metric', 'period_end'], 'license_usage_counter_vendor_metric_idx');
        });

        Schema::create('license_usage_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('counter_id')->constrained('license_usage_counters', 'id', 'license_usage_events_counter_fk')->restrictOnDelete();
            $table->foreignUlid('license_id')->constrained('licenses', 'id', 'license_usage_events_license_fk')->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts', 'id', 'license_usage_events_vendor_fk')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products', 'id', 'license_usage_events_product_fk')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users', 'id', 'license_usage_events_actor_fk')->restrictOnDelete();
            $table->string('metric', 64);
            $table->string('period', 16);
            $table->unsignedBigInteger('quantity_delta');
            $table->unsignedBigInteger('resulting_quantity');
            $table->timestamp('period_start');
            $table->timestamp('period_end')->nullable();
            $table->string('reason', 500);
            $table->string('request_id', 128);
            $table->char('payload_sha256', 64);
            $table->json('event_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['vendor_account_id', 'request_id'], 'license_usage_events_request_unique');
            $table->index(['license_id', 'metric', 'created_at'], 'license_usage_events_metric_timeline_idx');
        });

        $this->createIntegrityTriggers();
    }

    public function down(): void
    {
        $this->dropIntegrityTriggers();
        Schema::dropIfExists('license_usage_events');
        Schema::dropIfExists('license_usage_counters');
    }

    private function createIntegrityTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_usage_counters_guard_insert
            BEFORE INSERT ON license_usage_counters
            FOR EACH ROW
            BEGIN
                IF NEW.period NOT IN ('monthly', 'annual', 'lifetime')
                    OR NEW.metric NOT REGEXP '^[a-z][a-z0-9_]{0,63}$'
                    OR NEW.quantity < 1
                    OR NEW.version <> 1
                    OR NEW.period_end IS NOT NULL AND NEW.period_end <= NEW.period_start
                    OR NOT EXISTS (
                        SELECT 1 FROM licenses
                        WHERE id = NEW.license_id
                            AND vendor_account_id = NEW.vendor_account_id
                            AND product_id = NEW.product_id
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence usage counter metadata or scope is invalid';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_usage_counters_guard_update
            BEFORE UPDATE ON license_usage_counters
            FOR EACH ROW
            BEGIN
                IF NEW.license_id <> OLD.license_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.product_id <> OLD.product_id
                    OR NEW.metric <> OLD.metric
                    OR NEW.period <> OLD.period
                    OR NEW.period_start <> OLD.period_start
                    OR NOT (NEW.period_end <=> OLD.period_end)
                    OR NEW.quantity <= OLD.quantity
                    OR NEW.version <> OLD.version + 1 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence usage counter identity is immutable and quantity must increase with one version';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_usage_events_guard_insert
            BEFORE INSERT ON license_usage_events
            FOR EACH ROW
            BEGIN
                IF NEW.quantity_delta < 1
                    OR NEW.resulting_quantity < NEW.quantity_delta
                    OR CHAR_LENGTH(NEW.payload_sha256) <> 64
                    OR NEW.payload_sha256 NOT REGEXP '^[a-f0-9]{64}$'
                    OR NOT EXISTS (
                        SELECT 1 FROM license_usage_counters
                        WHERE id = NEW.counter_id
                            AND license_id = NEW.license_id
                            AND vendor_account_id = NEW.vendor_account_id
                            AND product_id = NEW.product_id
                            AND metric = NEW.metric
                            AND period = NEW.period
                            AND period_start = NEW.period_start
                            AND (period_end <=> NEW.period_end)
                            AND quantity = NEW.resulting_quantity
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence usage event must match its committed counter projection';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_usage_events_prevent_update
            BEFORE UPDATE ON license_usage_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence usage events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_usage_events_prevent_delete
            BEFORE DELETE ON license_usage_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence usage events are immutable'
            SQL);
    }

    private function dropIntegrityTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS license_usage_events_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS license_usage_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_usage_events_guard_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS license_usage_counters_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_usage_counters_guard_insert');
    }
};
