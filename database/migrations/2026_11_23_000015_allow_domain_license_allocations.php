<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_guard_insert');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocations_guard_insert
            BEFORE INSERT ON license_allocations
            FOR EACH ROW
            BEGIN
                IF NEW.status <> 'active'
                    OR NEW.version <> 1
                    OR NEW.allocation_type NOT IN ('site', 'seat', 'user', 'domain')
                    OR CHAR_LENGTH(NEW.subject_hash) <> 64
                    OR NEW.subject_hash NOT REGEXP '^[a-f0-9]{64}$'
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
                        INNER JOIN vendor_accounts
                            ON vendor_accounts.id = licenses.vendor_account_id
                            AND vendor_accounts.status = 'active'
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
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS license_allocations_guard_insert');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER license_allocations_guard_insert
            BEFORE INSERT ON license_allocations
            FOR EACH ROW
            BEGIN
                IF NEW.status <> 'active'
                    OR NEW.version <> 1
                    OR NEW.allocation_type NOT IN ('site', 'seat', 'user')
                    OR CHAR_LENGTH(NEW.subject_hash) <> 64
                    OR NEW.subject_hash NOT REGEXP '^[a-f0-9]{64}$'
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
                        INNER JOIN vendor_accounts
                            ON vendor_accounts.id = licenses.vendor_account_id
                            AND vendor_accounts.status = 'active'
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
    }
};
