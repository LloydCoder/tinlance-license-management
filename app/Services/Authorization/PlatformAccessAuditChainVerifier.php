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
                // Serialize verification with appends even when the host uses READ COMMITTED.
                ->lockForUpdate()
                ->first();

            if ($head === null) {
                return false;
            }

            $expectedSequence = 1;
            $previousHash = null;

            foreach (DB::table('platform_access_audit_events')->orderBy('sequence')->cursor() as $event) {
                $hashVersion = (int) $event->hash_version;
                if (
                    (int) $event->sequence !== $expectedSequence
                    || ! in_array($hashVersion, [1, 2], true)
                    || ($event->previous_hash === null ? null : (string) $event->previous_hash) !== $previousHash
                    || ($hashVersion === 1 && ($event->details_ref_id !== null || $event->details_hash !== null))
                    || ($hashVersion === 2 && ! $this->verifyCustomerProfileDetails($event))
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
                    $hashVersion,
                    $event->details_ref_id === null ? null : (string) $event->details_ref_id,
                    $event->details_hash === null ? null : (string) $event->details_hash,
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

    private function verifyCustomerProfileDetails(object $auditEvent): bool
    {
        if (
            ! in_array($auditEvent->action, [
                'customer_organization.created',
                'customer_organization.profile_updated',
            ], true)
            || ! is_string($auditEvent->details_ref_id)
            || ! is_string($auditEvent->details_hash)
        ) {
            return false;
        }

        $profileEvent = DB::table('customer_organization_profile_events')
            ->join(
                'customer_organizations',
                'customer_organizations.id',
                '=',
                'customer_organization_profile_events.customer_organization_id',
            )
            ->where('customer_organization_profile_events.id', $auditEvent->details_ref_id)
            ->first([
                'customer_organization_profile_events.id',
                'customer_organization_profile_events.customer_organization_id',
                'customer_organization_profile_events.actor_user_id',
                'customer_organization_profile_events.event_type',
                'customer_organization_profile_events.reason',
                'customer_organization_profile_events.changed_fields',
                'customer_organization_profile_events.change_digest',
                'customer_organization_profile_events.request_id',
                'customer_organization_profile_events.created_at',
                'customer_organizations.vendor_account_id',
            ]);

        $expectedEventType = $auditEvent->action === 'customer_organization.created'
            ? 'created'
            : 'profile_updated';

        if (
            $profileEvent === null
            || $profileEvent->event_type !== $expectedEventType
            || (string) $profileEvent->customer_organization_id !== (string) $auditEvent->resource_id
            || (string) $profileEvent->actor_user_id !== (string) $auditEvent->actor_user_id
            || (string) $profileEvent->vendor_account_id !== (string) $auditEvent->tenant_vendor_account_id
            || (string) $profileEvent->request_id !== (string) $auditEvent->request_id
            || ! is_string($profileEvent->change_digest)
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $profileEvent->change_digest) !== 1
        ) {
            return false;
        }

        $changedFields = json_decode((string) $profileEvent->changed_fields, true);
        if (! is_array($changedFields) || ! array_is_list($changedFields)) {
            return false;
        }

        $expectedDetailsHash = $this->hasher->hashCustomerOrganizationProfileEvent(
            (string) $profileEvent->id,
            (string) $profileEvent->customer_organization_id,
            (string) $profileEvent->actor_user_id,
            (string) $profileEvent->event_type,
            (string) $profileEvent->reason,
            $changedFields,
            (string) $profileEvent->change_digest,
            (string) $profileEvent->request_id,
            (string) $profileEvent->created_at,
        );

        return hash_equals((string) $auditEvent->details_hash, $expectedDetailsHash);
    }
}
