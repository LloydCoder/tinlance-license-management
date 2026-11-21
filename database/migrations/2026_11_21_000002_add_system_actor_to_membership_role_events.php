<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const TABLES = [
        'vendor_membership_role_events',
        'customer_membership_role_events',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['actor_user_id']);
                $table->ulid('actor_user_id')->nullable()->change();
                $table->string('actor_type', 16)->default('user');
                $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (DB::table($tableName)->whereNull('actor_user_id')->exists()) {
                throw new RuntimeException('Cannot roll back system-attributed membership role events without losing their actor semantics.');
            }
        }

        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['actor_user_id']);
                $table->dropColumn('actor_type');
                $table->ulid('actor_user_id')->nullable(false)->change();
                $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }
};
