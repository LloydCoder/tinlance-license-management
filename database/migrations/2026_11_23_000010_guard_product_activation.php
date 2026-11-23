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

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER products_guard_update
            BEFORE UPDATE ON products
            FOR EACH ROW
            BEGIN
                IF NEW.public_id <> OLD.public_id
                    OR NEW.vendor_account_id <> OLD.vendor_account_id
                    OR NEW.created_by_user_id <> OLD.created_by_user_id
                    OR NEW.version <> OLD.version + 1 THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Product identity is immutable and mutations must advance the aggregate version';
                END IF;

                IF NEW.status = OLD.status THEN
                    IF OLD.status <> 'draft' THEN
                        SIGNAL SQLSTATE '45000'
                            SET MESSAGE_TEXT = 'Only draft products may be edited';
                    END IF;
                ELSEIF NOT (
                    OLD.status = 'draft'
                    AND NEW.status = 'active'
                    AND NEW.slug <=> OLD.slug
                    AND NEW.name <=> OLD.name
                    AND NEW.description <=> OLD.description
                    AND EXISTS (
                        SELECT 1
                        FROM release_channels AS channels
                        INNER JOIN product_versions AS versions
                            ON versions.id = channels.current_product_version_id
                        WHERE channels.product_id = OLD.id
                            AND channels.status = 'active'
                            AND versions.product_id = OLD.id
                            AND versions.status = 'published'
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'A product may activate only after a published version is promoted to an active release channel';
                END IF;
            END
            SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS products_guard_update');
        }
    }
};
