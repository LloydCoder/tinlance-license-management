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
        Schema::create('licenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'licenses_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('customer_organization_id')
                ->constrained('customer_organizations', 'id', 'licenses_customer_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'licenses_product_fk')
                ->restrictOnDelete();
            $table->foreignUlid('plan_version_id')
                ->constrained('plan_versions', 'id', 'licenses_plan_version_fk')
                ->restrictOnDelete();
            $table->string('public_reference', 64);
            $table->char('key_verifier', 64)->unique('licenses_key_verifier_unique');
            $table->string('pepper_key_id', 64);
            $table->string('status', 16)->default('issued');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUlid('created_by_user_id')
                ->constrained('users', 'id', 'licenses_creator_fk')
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(['vendor_account_id', 'public_reference'], 'licenses_vendor_reference_unique');
            $table->index(['vendor_account_id', 'customer_organization_id', 'status'], 'licenses_customer_status_idx');
            $table->index(['product_id', 'status', 'expires_at'], 'licenses_product_expiry_idx');
            $table->index(['plan_version_id', 'status'], 'licenses_plan_status_idx');
        });

        Schema::create('license_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('license_id')
                ->constrained('licenses', 'id', 'license_events_license_fk')
                ->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')
                ->constrained('vendor_accounts', 'id', 'license_events_vendor_fk')
                ->restrictOnDelete();
            $table->foreignUlid('product_id')
                ->constrained('products', 'id', 'license_events_product_fk')
                ->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->foreignUlid('actor_user_id')->nullable()
                ->constrained('users', 'id', 'license_events_actor_fk')
                ->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->string('reason', 500);
            $table->string('request_id', 128);
            $table->char('payload_sha256', 64);
            $table->json('event_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['license_id', 'created_at'], 'license_events_timeline_idx');
            $table->unique(['vendor_account_id', 'request_id'], 'license_events_request_unique');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('license_events');
        Schema::dropIfExists('licenses');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_events_guard_insert
            BEFORE INSERT ON license_events
            FOR EACH ROW
            BEGIN
                IF NEW.actor_type NOT IN ('user', 'system')
                    OR (NEW.actor_type = 'user' AND NEW.actor_user_id IS NULL)
                    OR (NEW.actor_type = 'system' AND NEW.actor_user_id IS NOT NULL)
                    OR NOT EXISTS (
                        SELECT 1 FROM licenses
                        WHERE id = NEW.license_id
                            AND vendor_account_id = NEW.vendor_account_id
                            AND product_id = NEW.product_id
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence events must use a valid actor and matching licence scope';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_events_prevent_update
            BEFORE UPDATE ON license_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_events_prevent_delete
            BEFORE DELETE ON license_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence events are immutable'
            SQL);
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS license_events_guard_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS license_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_events_prevent_delete');
    }
};
