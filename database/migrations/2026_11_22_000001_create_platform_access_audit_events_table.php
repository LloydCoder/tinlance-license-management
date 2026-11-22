<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_access_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->string('resource_type', 128);
            $table->string('resource_id', 26);
            $table->foreignUlid('tenant_vendor_account_id')->constrained('vendor_accounts')->restrictOnDelete();
            $table->string('outcome', 16);
            $table->string('reason_code', 64);
            $table->string('request_id', 128);
            $table->timestamp('created_at');
            $table->index(['actor_user_id', 'created_at'], 'platform_access_actor_created_idx');
            $table->index(['tenant_vendor_account_id', 'created_at'], 'platform_access_tenant_created_idx');
            $table->index(['resource_type', 'resource_id'], 'platform_access_resource_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_access_audit_events');
    }
};
