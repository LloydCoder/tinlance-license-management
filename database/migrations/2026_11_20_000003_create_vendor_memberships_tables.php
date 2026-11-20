<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_memberships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('vendor_account_id')->constrained('vendor_accounts')->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('invited')->index();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['vendor_account_id', 'user_id']);
        });

        Schema::create('vendor_membership_roles', function (Blueprint $table): void {
            $table->foreignUlid('vendor_membership_id')->constrained('vendor_memberships')->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->primary(['vendor_membership_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_membership_roles');
        Schema::dropIfExists('vendor_memberships');
    }
};
