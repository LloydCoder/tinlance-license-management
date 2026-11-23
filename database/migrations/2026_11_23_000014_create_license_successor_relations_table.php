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
        Schema::create('license_successor_relations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('source_license_id')->constrained('licenses', 'id', 'license_successors_source_fk')->restrictOnDelete();
            $table->foreignUlid('successor_license_id')->constrained('licenses', 'id', 'license_successors_successor_fk')->restrictOnDelete();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts', 'id', 'license_successors_vendor_fk')->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained('products', 'id', 'license_successors_product_fk')->restrictOnDelete();
            $table->foreignUlid('source_customer_organization_id')->constrained('customer_organizations', 'id', 'license_successors_source_customer_fk')->restrictOnDelete();
            $table->foreignUlid('destination_customer_organization_id')->constrained('customer_organizations', 'id', 'license_successors_destination_customer_fk')->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->constrained('users', 'id', 'license_successors_actor_fk')->restrictOnDelete();
            $table->string('relation_type', 16);
            $table->string('reason', 500);
            $table->char('idempotency_key_sha256', 64);
            $table->char('request_fingerprint_sha256', 64);
            $table->string('request_id', 128);
            $table->char('payload_sha256', 64);
            $table->json('event_payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique('source_license_id', 'license_successors_source_unique');
            $table->unique('successor_license_id', 'license_successors_successor_unique');
            $table->unique(['vendor_account_id', 'request_id'], 'license_successors_request_unique');
            $table->unique(['vendor_account_id', 'idempotency_key_sha256'], 'license_successors_idempotency_unique');
        });

        $this->createIntegrityTriggers();
    }

    public function down(): void
    {
        $this->dropIntegrityTriggers();
        Schema::dropIfExists('license_successor_relations');
    }

    private function createIntegrityTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_successors_guard_insert
            BEFORE INSERT ON license_successor_relations
            FOR EACH ROW
            BEGIN
                IF NEW.relation_type NOT IN ('transfer', 'replacement')
                    OR CHAR_LENGTH(NEW.idempotency_key_sha256) <> 64
                    OR NEW.idempotency_key_sha256 NOT REGEXP '^[a-f0-9]{64}$'
                    OR CHAR_LENGTH(NEW.request_fingerprint_sha256) <> 64
                    OR NEW.request_fingerprint_sha256 NOT REGEXP '^[a-f0-9]{64}$'
                    OR CHAR_LENGTH(NEW.payload_sha256) <> 64
                    OR NEW.payload_sha256 NOT REGEXP '^[a-f0-9]{64}$'
                    OR NOT EXISTS (
                        SELECT 1 FROM licenses
                        WHERE id = NEW.source_license_id
                            AND vendor_account_id = NEW.vendor_account_id
                            AND product_id = NEW.product_id
                            AND customer_organization_id = NEW.source_customer_organization_id
                            AND status = 'revoked'
                    )
                    OR NOT EXISTS (
                        SELECT 1 FROM licenses
                        WHERE id = NEW.successor_license_id
                            AND vendor_account_id = NEW.vendor_account_id
                            AND product_id = NEW.product_id
                            AND customer_organization_id = NEW.destination_customer_organization_id
                            AND status = 'issued'
                    ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence successor relation scope or lifecycle state is invalid';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_successors_prevent_update
            BEFORE UPDATE ON license_successor_relations
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence successor relations are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_successors_prevent_delete
            BEFORE DELETE ON license_successor_relations
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Licence successor relations are immutable'
            SQL);
    }

    private function dropIntegrityTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS license_successors_prevent_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS license_successors_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS license_successors_guard_insert');
    }
};
