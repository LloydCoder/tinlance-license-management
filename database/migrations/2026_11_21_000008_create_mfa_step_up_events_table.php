<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_step_up_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 16);
            $table->string('outcome', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at'], 'mfa_step_up_events_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_step_up_events');
    }
};
