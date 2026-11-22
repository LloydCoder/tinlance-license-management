<?php

declare(strict_types=1);

namespace App\Services\Authorization;

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
}
