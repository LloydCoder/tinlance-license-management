<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('scope', 20);
            $table->string('slug', 80);
            $table->string('name', 120);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['scope', 'slug']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('scope', 20);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'name'], 'permissions_scope_name_uq');
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
