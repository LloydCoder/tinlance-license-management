<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use LogicException;

return new class extends Migration
{
    private const EVENT_TABLES = [
        'vendor_membership_role_events',
        'customer_membership_role_events',
    ];

    public function up(): void
    {
        foreach (self::EVENT_TABLES as $eventTable) {
            Schema::table($eventTable, function (Blueprint $table): void {
                $table->dropForeign(['actor_user_id']);
            });

            Schema::table($eventTable, function (Blueprint $table): void {
                $table->ulid('actor_user_id')->nullable()->change();
                $table->string('actor_type', 16)->default('user');
            });

            Schema::table($eventTable, function (Blueprint $table): void {
                $table->foreign('actor_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::EVENT_TABLES as $eventTable) {
            if (DB::table($eventTable)->whereNull('actor_user_id')->exists()) {
                throw new LogicException('Cannot roll back system actor support while system-attributed membership events exist.');
            }

            Schema::table($eventTable, function (Blueprint $table): void {
                $table->dropForeign(['actor_user_id']);
            });

            Schema::table($eventTable, function (Blueprint $table): void {
                $table->dropColumn('actor_type');
                $table->ulid('actor_user_id')->nullable(false)->change();
            });

            Schema::table($eventTable, function (Blueprint $table): void {
                $table->foreign('actor_user_id')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            });
        }
    }
};
