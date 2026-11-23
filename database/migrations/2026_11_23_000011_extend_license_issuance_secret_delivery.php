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
        Schema::table('license_issuance_requests', function (Blueprint $table): void {
            $table->timestamp('response_delivered_at')->nullable()->after('response_ciphertext');
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_issuance_requests_guard_update
            BEFORE UPDATE ON license_issuance_requests
            FOR EACH ROW
            BEGIN
                IF NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.actor_user_id <> OLD.actor_user_id
                    OR NEW.operation <> OLD.operation
                    OR NEW.idempotency_key_sha256 <> OLD.idempotency_key_sha256
                    OR NEW.request_fingerprint_sha256 <> OLD.request_fingerprint_sha256
                    OR NEW.expires_at <> OLD.expires_at
                    OR NEW.created_at <> OLD.created_at THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence issuance request identity is immutable';
                END IF;

                IF NOT (
                    (
                        OLD.status = 'processing'
                        AND NEW.status = 'completed'
                        AND OLD.license_id IS NULL
                        AND NEW.license_id IS NOT NULL
                        AND OLD.response_ciphertext IS NULL
                        AND NEW.response_ciphertext IS NOT NULL
                        AND OLD.response_delivered_at IS NULL
                        AND NEW.response_delivered_at IS NULL
                    )
                    OR
                    (
                        OLD.status = 'completed'
                        AND NEW.status = 'delivered'
                        AND OLD.license_id IS NOT NULL
                        AND NEW.license_id <=> OLD.license_id
                        AND OLD.response_ciphertext IS NOT NULL
                        AND NEW.response_ciphertext IS NULL
                        AND OLD.response_delivered_at IS NULL
                        AND NEW.response_delivered_at IS NOT NULL
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Licence issuance request state transition is invalid';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_issuance_requests_prevent_delete
            BEFORE DELETE ON license_issuance_requests
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Licence issuance request records are retained';
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS license_issuance_requests_guard_update');
            DB::unprepared('DROP TRIGGER IF EXISTS license_issuance_requests_prevent_delete');
        }

        Schema::table('license_issuance_requests', function (Blueprint $table): void {
            $table->dropColumn('response_delivered_at');
        });
    }
};
