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
        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'plans_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'plans_vendor_fk')
                ->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('description', 2000)->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('created_by_user_id')
                ->constrained('users', 'id', 'plans_creator_fk')
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'code'], 'plans_product_code_unique');
            $table->index(['vendor_account_id', 'status'], 'plans_vendor_status_idx');
        });

        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')
                ->constrained('plans', 'id', 'plan_versions_plan_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'plan_versions_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'plan_versions_vendor_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 16)->default('draft');
            $table->string('billing_model', 24);
            $table->char('currency', 3);
            $table->unsignedBigInteger('price_minor');
            $table->string('billing_interval', 16)->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('maintenance_days')->default(0);
            $table->json('entitlements');
            $table->char('snapshot_sha256', 64)->nullable();
            $table->foreignUlid('created_by_user_id')
                ->constrained('users', 'id', 'plan_versions_creator_fk')
                ->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'version_number'], 'plan_versions_plan_number_unique');
            $table->index(['vendor_account_id', 'product_id', 'status'], 'plan_versions_vendor_product_status_idx');
        });

        Schema::create('plan_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')
                ->constrained('plans', 'id', 'plan_events_plan_fk')
                ->restrictOnDelete();
            $table->foreignUlid('plan_version_id')
                ->nullable()
                ->constrained('plan_versions', 'id', 'plan_events_version_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'plan_events_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'plan_events_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'plan_events_actor_fk')
                ->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('reason', 500);
            $table->string('request_id', 128);
            $table->json('event_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['plan_id', 'created_at'], 'plan_events_timeline_idx');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('plan_events');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS plan_events_guard_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_events_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_versions_guard_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_versions_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS plan_versions_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS plans_guard_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS plans_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS plans_prevent_delete');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plans_guard_insert
            BEFORE INSERT ON plans
            FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM products
                    WHERE id = NEW.product_id AND vendor_account_id = NEW.vendor_account_id
                ) OR NOT EXISTS (
                    SELECT 1 FROM vendor_accounts
                    WHERE id = NEW.vendor_account_id AND status = 'active'
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Plans must reference an active product vendor tenant';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plans_guard_update
            BEFORE UPDATE ON plans
            FOR EACH ROW
            BEGIN
                IF OLD.status NOT IN ('draft', 'active')
                    OR NEW.product_id <> OLD.product_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.code <> OLD.code
                    OR NEW.name <> OLD.name
                    OR NOT (NEW.description <=> OLD.description)
                    OR NEW.created_by_user_id <> OLD.created_by_user_id
                    OR NEW.version <> OLD.version + 1 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Plan identity is immutable and updates must advance the aggregate version';
                END IF;

                IF NEW.status <> OLD.status AND NOT (
                    OLD.status = 'draft'
                    AND NEW.status = 'active'
                    AND EXISTS (
                        SELECT 1 FROM plan_versions
                        WHERE plan_id = OLD.id AND status = 'published'
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'A plan becomes active only after its first version is published';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_versions_guard_insert
            BEFORE INSERT ON plan_versions
            FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM plans
                    WHERE id = NEW.plan_id
                        AND product_id = NEW.product_id
                        AND vendor_account_id = NEW.vendor_account_id
                        AND status IN ('draft', 'active')
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Plan versions must belong to an active plan in the same tenant';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_events_guard_insert
            BEFORE INSERT ON plan_events
            FOR EACH ROW
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                    FROM plans
                    INNER JOIN products ON products.id = plans.product_id
                    WHERE plans.id = NEW.plan_id
                        AND plans.product_id = NEW.product_id
                        AND plans.vendor_account_id = NEW.vendor_account_id
                        AND products.vendor_account_id = NEW.vendor_account_id
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Plan events must remain within the plan product vendor tenant';
                END IF;

                IF NEW.plan_version_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM plan_versions
                    WHERE id = NEW.plan_version_id
                        AND plan_id = NEW.plan_id
                        AND product_id = NEW.product_id
                        AND vendor_account_id = NEW.vendor_account_id
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Plan event version must match its parent plan tenant';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_events_prevent_update
            BEFORE UPDATE ON plan_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Plan events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_events_prevent_delete
            BEFORE DELETE ON plan_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Plan events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_versions_guard_update
            BEFORE UPDATE ON plan_versions
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft'
                    OR NEW.plan_id <> OLD.plan_id
                    OR NEW.product_id <> OLD.product_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.version_number <> OLD.version_number
                    OR NEW.created_by_user_id <> OLD.created_by_user_id THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Published plan versions and their identity are immutable';
                END IF;

                IF NEW.status = 'published' THEN
                    IF NEW.published_at IS NULL
                        OR NEW.snapshot_sha256 IS NULL
                        OR NEW.billing_model <> OLD.billing_model
                        OR NEW.currency <> OLD.currency
                        OR NEW.price_minor <> OLD.price_minor
                        OR NOT (NEW.billing_interval <=> OLD.billing_interval)
                        OR NEW.trial_days <> OLD.trial_days
                        OR NEW.maintenance_days <> OLD.maintenance_days
                        OR NOT (NEW.entitlements <=> OLD.entitlements) THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Publishing must preserve the complete draft commercial terms and entitlement snapshot';
                    END IF;
                ELSEIF NEW.status <> 'draft' OR NEW.published_at IS NOT NULL OR NEW.snapshot_sha256 IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Invalid plan version transition';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plan_versions_prevent_delete
            BEFORE DELETE ON plan_versions
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Plan versions are retained'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER plans_prevent_delete
            BEFORE DELETE ON plans
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Plans are retained'
            SQL);
    }
};
