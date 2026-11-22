<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use Illuminate\Support\Facades\DB;

final readonly class PlatformAccessAuditChainVerifier
{
    public function __construct(private PlatformAccessAuditChainHasher $hasher) {}

    public function verify(): bool
    {
        return DB::transaction(function (): bool {
            $head = DB::table('platform_access_audit_chain_heads')
                ->where('stream_key', 'platform_access')
                ->first();

            if ($head === null) {
                return false;
            }

            $expectedSequence = 1;
            $previousHash = null;

            foreach (DB::table('platform_access_audit_events')->orderBy('sequence')->cursor() as $event) {
                if (
                    (int) $event->sequence !== $expectedSequence
                    || (int) $event->hash_version !== 1
                    || ($event->previous_hash === null ? null : (string) $event->previous_hash) !== $previousHash
                ) {
                    return false;
                }

                $expectedHash = $this->hasher->hashEvent(
                    (int) $event->sequence,
                    $event->previous_hash === null ? null : (string) $event->previous_hash,
                    (string) $event->id,
                    (string) $event->actor_user_id,
                    (string) $event->action,
                    (string) $event->resource_type,
                    (string) $event->resource_id,
                    (string) $event->tenant_vendor_account_id,
                    (string) $event->outcome,
                    (string) $event->reason_code,
                    (string) $event->request_id,
                    (string) $event->created_at,
                );

                if (! hash_equals((string) $event->event_hash, $expectedHash)) {
                    return false;
                }

                $previousHash = $expectedHash;
                $expectedSequence++;
            }

            $lastSequence = $expectedSequence - 1;
            $lastHash = $head->last_hash === null ? null : (string) $head->last_hash;

            return (int) $head->last_sequence === $lastSequence && $lastHash === $previousHash;
        });
    }
}
