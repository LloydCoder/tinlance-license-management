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
    public function hashProductEvent(
        string $id,
        string $productId,
        string $vendorAccountId,
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
            'product_id' => $productId,
            'vendor_account_id' => $vendorAccountId,
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

    /**
     * @param  array<string, mixed>  $eventPayload
     *
     * @throws JsonException
     */
    public function hashPlanEvent(
        string $id,
        string $planId,
        ?string $planVersionId,
        string $productId,
        string $vendorAccountId,
        string $actorUserId,
        string $eventType,
        string $reason,
        string $requestId,
        array $eventPayload,
        string $createdAt,
    ): string {
        $canonicalEvent = [
            'version' => 1,
            'id' => $id,
            'plan_id' => $planId,
            'plan_version_id' => $planVersionId,
            'product_id' => $productId,
            'vendor_account_id' => $vendorAccountId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'reason' => $reason,
            'request_id' => $requestId,
            'event_payload' => $this->sortJsonObjectKeys($eventPayload),
            'created_at' => $createdAt,
        ];

        return hash('sha256', json_encode(
            $canonicalEvent,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sortJsonObjectKeys(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = array_is_list($entry)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->sortNestedJson($item) : $item, $entry)
                    : $this->sortNestedJson($entry);
            }
        }

        ksort($value, SORT_STRING);

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sortNestedJson(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = array_is_list($entry)
                    ? array_map(fn (mixed $item): mixed => is_array($item) ? $this->sortNestedJson($item) : $item, $entry)
                    : $this->sortNestedJson($entry);
            }
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @throws JsonException
     */
    public function hashReleaseChannelEvent(
        string $id,
        string $releaseChannelId,
        string $productId,
        string $vendorAccountId,
        string $actorUserId,
        string $eventType,
        int $channelVersionNumber,
        ?string $previousProductVersionId,
        ?string $productVersionId,
        string $reason,
        string $requestId,
        string $createdAt,
    ): string {
        $canonicalEvent = [
            'version' => 1,
            'id' => $id,
            'release_channel_id' => $releaseChannelId,
            'product_id' => $productId,
            'vendor_account_id' => $vendorAccountId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'channel_version_number' => $channelVersionNumber,
            'previous_product_version_id' => $previousProductVersionId,
            'product_version_id' => $productVersionId,
            'reason' => $reason,
            'request_id' => $requestId,
            'created_at' => $createdAt,
        ];

        return hash('sha256', json_encode(
            $canonicalEvent,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  list<string>  $changedFields
     *
     * @throws JsonException
     */
    public function hashProductVersionEvent(
        string $id,
        string $productVersionId,
        string $productId,
        string $vendorAccountId,
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
            'product_version_id' => $productVersionId,
            'product_id' => $productId,
            'vendor_account_id' => $vendorAccountId,
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

    /**
     * @throws JsonException
     */
    public function hashProductAssignmentEvent(
        string $id,
        string $assignmentId,
        string $productId,
        string $vendorAccountId,
        string $actorUserId,
        string $targetVendorMembershipId,
        string $eventType,
        string $reason,
        string $requestId,
        string $createdAt,
    ): string {
        $canonicalEvent = [
            'version' => 1,
            'id' => $id,
            'assignment_id' => $assignmentId,
            'product_id' => $productId,
            'vendor_account_id' => $vendorAccountId,
            'actor_user_id' => $actorUserId,
            'target_vendor_membership_id' => $targetVendorMembershipId,
            'event_type' => $eventType,
            'reason' => $reason,
            'request_id' => $requestId,
            'created_at' => $createdAt,
        ];

        return hash('sha256', json_encode(
            $canonicalEvent,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  list<string>  $changedFields
     *
     * @throws JsonException
     */
    public function hashVendorAccountProfileEvent(
        string $id,
        string $vendorAccountId,
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
            'vendor_account_id' => $vendorAccountId,
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
