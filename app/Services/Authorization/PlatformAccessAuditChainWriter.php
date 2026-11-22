<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\PlatformAccessAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PlatformAccessAuditChainWriter
{
    public function __construct(private PlatformAccessAuditChainHasher $hasher) {}

    public function append(
        string $actorUserId,
        string $action,
        string $resourceType,
        string $resourceId,
        string $tenantVendorAccountId,
        string $outcome,
        string $reasonCode,
        string $requestId,
    ): PlatformAccessAuditEvent {
        return DB::transaction(function () use (
            $actorUserId,
            $action,
            $resourceType,
            $resourceId,
            $tenantVendorAccountId,
            $outcome,
            $reasonCode,
            $requestId,
        ): PlatformAccessAuditEvent {
            $head = DB::table('platform_access_audit_chain_heads')
                ->where('stream_key', 'platform_access')
                ->lockForUpdate()
                ->first();

            if ($head === null) {
                throw new RuntimeException('Platform access audit chain head is missing.');
            }

            $sequence = (int) $head->last_sequence + 1;
            $previousHash = $head->last_hash === null ? null : (string) $head->last_hash;
            $id = (string) Str::ulid();
            $createdAt = now()->utc()->format('Y-m-d H:i:s');
            $eventHash = $this->hasher->hashEvent(
                $sequence,
                $previousHash,
                $id,
                $actorUserId,
                $action,
                $resourceType,
                $resourceId,
                $tenantVendorAccountId,
                $outcome,
                $reasonCode,
                $requestId,
                $createdAt,
            );

            $event = PlatformAccessAuditEvent::query()->create([
                'id' => $id,
                'sequence' => $sequence,
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
                'hash_version' => 1,
                'actor_user_id' => $actorUserId,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'tenant_vendor_account_id' => $tenantVendorAccountId,
                'outcome' => $outcome,
                'reason_code' => $reasonCode,
                'request_id' => $requestId,
                'created_at' => $createdAt,
            ]);

            DB::table('platform_access_audit_chain_heads')
                ->where('stream_key', 'platform_access')
                ->update([
                    'last_sequence' => $sequence,
                    'last_hash' => $eventHash,
                    'updated_at' => $createdAt,
                ]);

            return $event;
        }, 3);
    }
}
