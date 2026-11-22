<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use InvalidArgumentException;
use JsonException;

final class PlatformAccessAuditChainHasher
{
    /**
     * @throws JsonException
     */
    public function hashEvent(
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
        int $hashVersion = 1,
        ?string $detailsReferenceId = null,
        ?string $detailsHash = null,
    ): string {
        if (
            ! in_array($hashVersion, [1, 2], true)
            || (($detailsReferenceId === null) !== ($detailsHash === null))
            || ($hashVersion === 1 && $detailsReferenceId !== null)
            || ($hashVersion === 2 && (
                $detailsReferenceId === null
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/D', strtoupper($detailsReferenceId)) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $detailsHash ?? '') !== 1
            ))
        ) {
            throw new InvalidArgumentException('Platform access audit hash metadata is invalid.');
        }

        $canonicalEvent = [
            'version' => $hashVersion,
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

        if ($hashVersion === 2) {
            $canonicalEvent['details_ref_id'] = $detailsReferenceId;
            $canonicalEvent['details_hash'] = $detailsHash;
        }

        return hash('sha256', json_encode(
            $canonicalEvent,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array{before: array<string, scalar|null>, after: array<string, scalar|null>}  $changes
     *
     * @throws JsonException
     */
    public function hashProfileChangeSet(array $changes): string
    {
        foreach (['before', 'after'] as $side) {
            $values = $changes[$side];
            ksort($values, SORT_STRING);
            $changes[$side] = $values;
        }

        return hash('sha256', json_encode(
            $changes,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  list<string>  $changedFields
     *
     * @throws JsonException
     */
    public function hashCustomerOrganizationProfileEvent(
        string $id,
        string $organizationId,
        string $actorUserId,
        string $eventType,
        string $reason,
        array $changedFields,
        string $changeDigest,
        string $requestId,
        string $createdAt,
    ): string {
        sort($changedFields, SORT_STRING);

        $canonicalEvent = [
            'version' => 1,
            'id' => $id,
            'customer_organization_id' => $organizationId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'reason' => $reason,
            'changed_fields' => $changedFields,
            'change_digest' => $changeDigest,
            'request_id' => $requestId,
            'created_at' => $createdAt,
        ];

        return hash('sha256', json_encode(
            $canonicalEvent,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
