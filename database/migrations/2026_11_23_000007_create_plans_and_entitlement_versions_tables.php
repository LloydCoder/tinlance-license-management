<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained('products', 'id', 'plans_product_fk')->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts', 'id', 'plans_vendor_fk')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('description', 2000)->nullable();
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('created_by_user_id')->constrained('users', 'id', 'plans_creator_fk')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'code'], 'plans_product_code_unique');
            $table->index(['vendor_account_id', 'status'], 'plans_vendor_status_idx');
        });

        Schema::create('plan_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained('plans', 'id', 'plan_versions_plan_fk')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products', 'id', 'plan_versions_product_fk')->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts', 'id', 'plan_versions_vendor_fk')->restrictOnDelete();
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
            $table->foreignUlid('created_by_user_id')->constrained('users', 'id', 'plan_versions_creator_fk')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'version_number'], 'plan_versions_plan_number_unique');
            $table->index(['vendor_account_id', 'product_id', 'status'], 'plan_versions_vendor_product_status_idx');
        });

        Schema::create('plan_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained('plans', 'id', 'plan_events_plan_fk')->restrictOnDelete();
            $table->foreignUlid('plan_version_id')->nullable()->constrained('plan_versions', 'id', 'plan_events_version_fk')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products', 'id', 'plan_events_product_fk')->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts', 'id', 'plan_events_vendor_fk')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users', 'id', 'plan_events_actor_fk')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('reason', 500);
            $table->string('request_id', 128);
            $table->json('event_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['plan_id', 'created_at'], 'plan_events_timeline_idx');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER plan_events_prevent_update BEFORE UPDATE ON plan_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan events are immutable'");
            DB::unprepared("CREATE TRIGGER plan_events_prevent_delete BEFORE DELETE ON plan_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan events are immutable'");
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER plan_versions_guard_update
                BEFORE UPDATE ON plan_versions
                FOR EACH ROW
                BEGIN
                    IF OLD.status <> 'draft' OR NEW.plan_id <> OLD.plan_id OR NEW.product_id <> OLD.product_id
                        OR NEW.vendor_account_id <> OLD.vendor_account_id OR NEW.version_number <> OLD.version_number THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published plan versions and their identity are immutable';
                    END IF;
                    IF NEW.status = 'published' THEN
                        IF NEW.published_at IS NULL OR NEW.snapshot_sha256 IS NULL THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published plan versions require a frozen snapshot';
                        END IF;
                    ELSEIF NEW.status <> 'draft' OR NEW.published_at IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid plan version transition';
                    END IF;
                END
                SQL);
            DB::unprepared("CREATE TRIGGER plan_versions_prevent_delete BEFORE DELETE ON plan_versions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plan versions are retained'");
            DB::unprepared("CREATE TRIGGER plans_prevent_delete BEFORE DELETE ON plans FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Plans are retained'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (['plan_events_prevent_update','plan_events_prevent_delete','plan_versions_guard_update','plan_versions_prevent_delete','plans_prevent_delete'] as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
        Schema::dropIfExists('plan_events');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
    }
};
