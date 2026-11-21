<?php

declare(strict_types=1);

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;
use Illuminate\\Support\\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_installations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('installation_key', 32)->unique();
            $table->foreignUlid('bootstrapped_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('bootstrapped_at')->nullable();
            $table->timestamps();
        });

        DB::table('platform_installations')->insert([
            'id' => (string) Str::ulid(),
            'installation_key' => 'primary',
            'bootstrapped_user_id' => null,
            'bootstrapped_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_installations');
    }
};
