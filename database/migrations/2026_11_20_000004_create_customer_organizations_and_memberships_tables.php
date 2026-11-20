<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts')->restrictOnDelete();
            $table->string('external_reference', 100)->nullable();
            $table->string('legal_name', 255);
            $table->string('display_name', 160);
            $table->string('status', 20)->default('active')->index();
            $table->string('billing_email', 254)->nullable();
            $table->timestamps();
            $table->unique(['vendor_account_id', 'external_reference'], 'cust_org_vendor_extref_uq');
            $table->index(['vendor_account_id', 'status']);
        });

        Schema::create('customer_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_organization_id')->constrained('customer_organizations')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('invited')->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_organization_id', 'user_id']);
        });

        Schema::create('customer_membership_roles', function (Blueprint $table): void {
            $table->foreignUlid('customer_membership_id')->constrained('customer_memberships')->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->primary(['customer_membership_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_membership_roles');
        Schema::dropIfExists('customer_memberships');
        Schema::dropIfExists('customer_organizations');
    }
};
