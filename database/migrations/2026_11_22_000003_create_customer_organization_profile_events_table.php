<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_organization_profile_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_organization_id')
                ->constrained('customer_organizations', 'id', 'cust_org_profile_events_org_fk')
                ->restrictOnDelete();
            $table->foreignUlid('actor_user_id')
                ->constrained('users', 'id', 'cust_org_profile_events_actor_fk')
                ->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('reason', 500);
            $table->json('changed_fields');
            $table->string('request_id', 128);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['customer_organization_id', 'created_at'], 'customer_org_profile_events_timeline_idx');
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();
        Schema::dropIfExists('customer_organization_profile_events');
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS customer_org_profile_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS customer_org_profile_events_prevent_delete');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER customer_org_profile_events_prevent_update
            BEFORE UPDATE ON customer_organization_profile_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Customer organization profile events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER customer_org_profile_events_prevent_delete
            BEFORE DELETE ON customer_organization_profile_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Customer organization profile events are immutable'
            SQL);
    }
};
