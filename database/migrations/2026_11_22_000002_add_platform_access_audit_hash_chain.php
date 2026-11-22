<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropImmutableTriggers();

        Schema::table('platform_access_audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('sequence')->nullable()->unique('platform_access_sequence_unique');
            $table->char('previous_hash', 64)->nullable();
            $table->char('event_hash', 64)->nullable()->unique('platform_access_event_hash_unique');
            $table->unsignedTinyInteger('hash_version')->default(1);
        });

        Schema::create('platform_access_audit_chain_heads', function (Blueprint $table): void {
            $table->string('stream_key', 64)->primary();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('updated_at');
        });

        $now = now()->utc()->format('Y-m-d H:i:s');
        DB::table('platform_access_audit_chain_heads')->insert([
            'stream_key' => 'platform_access',
            'last_sequence' => 0,
            'last_hash' => null,
            'updated_at' => $now,
        ]);

        $sequence = 0;
        $previousHash = null;

        foreach (DB::table('platform_access_audit_events')->orderBy('created_at')->orderBy('id')->get() as $event) {
            $sequence++;
            $createdAt = (string) $event->created_at;
            $eventHash = $this->hashEvent(
                $sequence,
                $previousHash,
                (string) $event->id,
                (string) $event->actor_user_id,
                (string) $event->action,
                (string) $event->resource_type,
                (string) $event->resource_id,
                (string) $event->tenant_vendor_account_id,
                (string) $event->outcome,
                (string) $event->reason_code,
                (string) $event->request_id,
                $createdAt,
            );

            DB::table('platform_access_audit_events')
                ->where('id', $event->id)
                ->update([
                    'sequence' => $sequence,
                    'previous_hash' => $previousHash,
                    'event_hash' => $eventHash,
                    'hash_version' => 1,
                ]);

            $previousHash = $eventHash;
        }

        DB::table('platform_access_audit_chain_heads')
            ->where('stream_key', 'platform_access')
            ->update([
                'last_sequence' => $sequence,
                'last_hash' => $previousHash,
                'updated_at' => now()->utc()->format('Y-m-d H:i:s'),
            ]);

        Schema::table('platform_access_audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('sequence')->nullable(false)->change();
            $table->char('event_hash', 64)->nullable(false)->change();
        });

        $this->createImmutableTriggers();
    }

    public function down(): void
    {
        $this->dropImmutableTriggers();

        Schema::dropIfExists('platform_access_audit_chain_heads');

        Schema::table('platform_access_audit_events', function (Blueprint $table): void {
            $table->dropUnique('platform_access_sequence_unique');
            $table->dropUnique('platform_access_event_hash_unique');
            $table->dropColumn(['sequence', 'previous_hash', 'event_hash', 'hash_version']);
        });

        $this->createImmutableTriggers();
    }

    /**
     * @throws JsonException
     */
    private function hashEvent(
        int $sequence,
        ?string $previousHash,
        string $id,
        string $actorUserId,
        string $action,
        string $resourceType,
        string $resourceId,
        string $tenantVendorAccountId,
        string $outcome,
        string $reasonCode,
        string $requestId,
        string $createdAt,
    ): string {
        $canonicalEvent = [
            'version' => 1,
            'sequence' => $sequence,
            'previous_hash' => $previousHash,
            'id' => $id,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'tenant_vendor_account_id' => $tenantVendorAccountId,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'request_id' => $requestId,
            'created_at' => $createdAt,
        ];

        return hash('sha256', json_encode(
            $canonicalEvent,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function dropImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS platform_access_audit_events_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS platform_access_audit_events_prevent_delete');
    }

    private function createImmutableTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER platform_access_audit_events_prevent_update
            BEFORE UPDATE ON platform_access_audit_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Platform access audit events are immutable'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER platform_access_audit_events_prevent_delete
            BEFORE DELETE ON platform_access_audit_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Platform access audit events are immutable'
            SQL);
    }
};
