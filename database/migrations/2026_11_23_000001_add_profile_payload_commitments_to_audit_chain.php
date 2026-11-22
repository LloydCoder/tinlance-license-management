<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_access_audit_events', function (Blueprint $table): void {
            $table->string('details_ref_id', 64)->nullable();
            $table->char('details_hash', 64)->nullable();
        });

        Schema::table('customer_organization_profile_events', function (Blueprint $table): void {
            $table->char('change_digest', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_organization_profile_events', function (Blueprint $table): void {
            $table->dropColumn('change_digest');
        });

        Schema::table('platform_access_audit_events', function (Blueprint $table): void {
            $table->dropColumn(['details_ref_id', 'details_hash']);
        });
    }
};
