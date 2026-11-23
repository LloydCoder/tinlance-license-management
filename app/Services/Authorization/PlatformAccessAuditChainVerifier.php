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
                    || ($hashVersion === 2 && ! $this->verifyVersionTwoDetails($event))
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

            $chainMatchesHead = (int) $head->last_sequence === $lastSequence
                && $lastHash === $previousHash;

            return $chainMatchesHead && $this->verifyProductEventCoverage();
        });
    }

    private function verifyProductEventCoverage(): bool
    {
        $unreferencedEventsExist = DB::table('product_events')
            ->whereIn('product_events.event_type', ['created', 'updated', 'activated'])
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('platform_access_audit_events')
                    ->whereColumn('platform_access_audit_events.details_ref_id', 'product_events.id')
                    ->where('platform_access_audit_events.hash_version', 2)
                    ->whereIn('platform_access_audit_events.action', ['product.created', 'product.updated', 'product.activated']);
            })
            ->exists();

        if ($unreferencedEventsExist) {
            return false;
        }

        $duplicateReferencesExist = DB::table('platform_access_audit_events')
            ->select('details_ref_id')
            ->where('hash_version', 2)
            ->whereIn('action', ['product.created', 'product.updated', 'product.activated'])
            ->groupBy('details_ref_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateReferencesExist) {
            return false;
        }

        $unreferencedAssignmentEventsExist = DB::table('product_assignment_events')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('platform_access_audit_events')
                    ->whereColumn('platform_access_audit_events.details_ref_id', 'product_assignment_events.id')
                    ->where('platform_access_audit_events.hash_version', 2)
                    ->whereIn('platform_access_audit_events.action', ['product.assignment_granted', 'product.assignment_revoked']);
            })
            ->exists();

        if ($unreferencedAssignmentEventsExist) {
            return false;
        }

        $duplicateAssignmentReferencesExist = DB::table('platform_access_audit_events')
            ->select('details_ref_id')
            ->where('hash_version', 2)
            ->whereIn('action', ['product.assignment_granted', 'product.assignment_revoked'])
            ->groupBy('details_ref_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateAssignmentReferencesExist) {
            return false;
        }

        $unreferencedVersionEventsExist = DB::table('product_version_events')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('platform_access_audit_events')
                    ->whereColumn('platform_access_audit_events.details_ref_id', 'product_version_events.id')
                    ->where('platform_access_audit_events.hash_version', 2)
                    ->whereIn('platform_access_audit_events.action', ['product_version.created', 'product_version.updated', 'product_version.published']);
            })
            ->exists();

        if ($unreferencedVersionEventsExist) {
            return false;
        }

        $duplicateVersionReferencesExist = DB::table('platform_access_audit_events')
            ->select('details_ref_id')
            ->where('hash_version', 2)
            ->whereIn('action', ['product_version.created', 'product_version.updated', 'product_version.published'])
            ->groupBy('details_ref_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateVersionReferencesExist) {
            return false;
        }

        $unreferencedReleaseChannelEventsExist = DB::table('release_channel_events')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('platform_access_audit_events')
                    ->whereColumn('platform_access_audit_events.details_ref_id', 'release_channel_events.id')
                    ->where('platform_access_audit_events.hash_version', 2)
                    ->whereIn('platform_access_audit_events.action', ['release_channel.created', 'release_channel.promoted']);
            })
            ->exists();

        if ($unreferencedReleaseChannelEventsExist) {
            return false;
        }

        $duplicateReleaseChannelReferencesExist = DB::table('platform_access_audit_events')
            ->select('details_ref_id')
            ->where('hash_version', 2)
            ->whereIn('action', ['release_channel.created', 'release_channel.promoted'])
            ->groupBy('details_ref_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateReleaseChannelReferencesExist) {
            return false;
        }

        $invalidReleaseChannelSequencesExist = DB::table('release_channel_events')
            ->select('release_channel_id')
            ->groupBy('release_channel_id')
            ->havingRaw('MIN(channel_version_number) <> 1 OR MAX(channel_version_number) <> COUNT(*)')
            ->exists();

        if ($invalidReleaseChannelSequencesExist) {
            return false;
        }

        $releaseChannelStateMismatchExists = DB::table('release_channels as channels')
            ->leftJoin('release_channel_events as latest_event', function ($join): void {
                $join->on('latest_event.release_channel_id', '=', 'channels.id')
                    ->on('latest_event.channel_version_number', '=', 'channels.version_number');
            })
            ->where(function ($query): void {
                $query->whereNull('latest_event.id')
                    ->orWhere(function ($mismatch): void {
                        $mismatch->whereColumn('channels.current_product_version_id', '<>', 'latest_event.product_version_id')
                            ->orWhere(function ($nullMismatch): void {
                                $nullMismatch->whereNull('channels.current_product_version_id')
                                    ->whereNotNull('latest_event.product_version_id');
                            })
                            ->orWhere(function ($nullMismatch): void {
                                $nullMismatch->whereNotNull('channels.current_product_version_id')
                                    ->whereNull('latest_event.product_version_id');
                            });
                    });
            })
            ->exists();

        $unreferencedPlanEventsExist = DB::table('plan_events')
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('platform_access_audit_events')
                    ->whereColumn('platform_access_audit_events.details_ref_id', 'plan_events.id')
                    ->where('platform_access_audit_events.hash_version', 2)
                    ->whereIn('platform_access_audit_events.action', [
                        'plan.created',
                        'plan_version.created',
                        'plan_version.updated',
                        'plan_version.published',
                    ]);
            })
            ->exists();

        if ($unreferencedPlanEventsExist) {
            return false;
        }

        $duplicatePlanReferencesExist = DB::table('platform_access_audit_events')
            ->select('details_ref_id')
            ->where('hash_version', 2)
            ->whereIn('action', [
                'plan.created',
                'plan_version.created',
                'plan_version.updated',
                'plan_version.published',
            ])
            ->groupBy('details_ref_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        return ! $releaseChannelStateMismatchExists && ! $duplicatePlanReferencesExist;
    }

    private function verifyVersionTwoDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;

        return match ($row['action'] ?? null) {
            'customer_organization.created',
            'customer_organization.profile_updated' => $this->verifyCustomerProfileDetails($auditEvent),
            'vendor_account.profile_updated' => $this->verifyVendorProfileDetails($auditEvent),
            'product.created', 'product.updated', 'product.activated' => $this->verifyProductDetails($auditEvent),
            'product.assignment_granted', 'product.assignment_revoked' => $this->verifyProductAssignmentDetails($auditEvent),
            'product_version.created', 'product_version.updated', 'product_version.published' => $this->verifyProductVersionDetails($auditEvent),
            'release_channel.created', 'release_channel.promoted' => $this->verifyReleaseChannelDetails($auditEvent),
            'plan.created', 'plan_version.created', 'plan_version.updated', 'plan_version.published' => $this->verifyPlanDetails($auditEvent),
            default => false,
        };
    }

    private function verifyVendorProfileDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;
        $detailsReferenceId = $row['details_ref_id'] ?? null;
        $detailsHash = $row['details_hash'] ?? null;
        $resourceId = $row['resource_id'] ?? null;
        $actorUserId = $row['actor_user_id'] ?? null;
        $tenantVendorAccountId = $row['tenant_vendor_account_id'] ?? null;
        $requestId = $row['request_id'] ?? null;

        if (
            ! is_string($detailsReferenceId)
            || ! is_string($detailsHash)
            || ! is_string($resourceId)
            || ! is_string($actorUserId)
            || ! is_string($tenantVendorAccountId)
            || ! is_string($requestId)
        ) {
            return false;
        }

        $profileEvent = DB::table('vendor_account_profile_events')
            ->where('id', $detailsReferenceId)
            ->first();

        if (
            $profileEvent === null
            || (string) $profileEvent->vendor_account_id !== $resourceId
            || (string) $profileEvent->vendor_account_id !== $tenantVendorAccountId
            || (string) $profileEvent->actor_user_id !== $actorUserId
            || (string) $profileEvent->request_id !== $requestId
            || $profileEvent->event_type !== 'profile_updated'
            || ! is_string($profileEvent->change_digest)
            || preg_match('/\A[a-f0-9]{64}\z/D', $profileEvent->change_digest) !== 1
        ) {
            return false;
        }

        $changedFields = json_decode((string) $profileEvent->changed_fields, true);
        if (! is_array($changedFields) || ! array_is_list($changedFields)) {
            return false;
        }

        $expectedDetailsHash = $this->hasher->hashVendorAccountProfileEvent(
            (string) $profileEvent->id,
            (string) $profileEvent->vendor_account_id,
            (string) $profileEvent->actor_user_id,
            (string) $profileEvent->event_type,
            (string) $profileEvent->reason,
            $changedFields,
            (string) $profileEvent->change_digest,
            (string) $profileEvent->request_id,
            (string) $profileEvent->created_at,
        );

        return hash_equals($detailsHash, $expectedDetailsHash);
    }

    private function verifyProductDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;
        $detailsReferenceId = $row['details_ref_id'] ?? null;
        $detailsHash = $row['details_hash'] ?? null;
        $resourceId = $row['resource_id'] ?? null;
        $actorUserId = $row['actor_user_id'] ?? null;
        $tenantVendorAccountId = $row['tenant_vendor_account_id'] ?? null;
        $requestId = $row['request_id'] ?? null;
        $action = $row['action'] ?? null;

        if (
            ! is_string($detailsReferenceId)
            || ! is_string($detailsHash)
            || ! is_string($resourceId)
            || ! is_string($actorUserId)
            || ! is_string($tenantVendorAccountId)
            || ! is_string($requestId)
            || ! in_array($action, ['product.created', 'product.updated', 'product.activated'], true)
        ) {
            return false;
        }

        $productEvent = DB::table('product_events')
            ->join('products', 'products.id', '=', 'product_events.product_id')
            ->where('product_events.id', $detailsReferenceId)
            ->first([
                'product_events.id',
                'product_events.product_id',
                'product_events.vendor_account_id',
                'product_events.actor_user_id',
                'product_events.event_type',
                'product_events.reason',
                'product_events.changed_fields',
                'product_events.change_digest',
                'product_events.request_id',
                'product_events.created_at',
                'products.vendor_account_id as product_vendor_account_id',
            ]);

        $expectedEventType = match ($action) {
            'product.created' => 'created',
            'product.updated' => 'updated',
            default => 'activated',
        };

        if (
            $productEvent === null
            || $productEvent->event_type !== $expectedEventType
            || (string) $productEvent->product_id !== $resourceId
            || (string) $productEvent->vendor_account_id !== $tenantVendorAccountId
            || (string) $productEvent->product_vendor_account_id !== $tenantVendorAccountId
            || (string) $productEvent->actor_user_id !== $actorUserId
            || (string) $productEvent->request_id !== $requestId
            || ! is_string($productEvent->change_digest)
            || preg_match('/\A[a-f0-9]{64}\z/D', $productEvent->change_digest) !== 1
        ) {
            return false;
        }

        $changedFields = json_decode((string) $productEvent->changed_fields, true);
        if (! is_array($changedFields) || ! array_is_list($changedFields)) {
            return false;
        }

        $expectedDetailsHash = $this->hasher->hashProductEvent(
            (string) $productEvent->id,
            (string) $productEvent->product_id,
            (string) $productEvent->vendor_account_id,
            (string) $productEvent->actor_user_id,
            (string) $productEvent->event_type,
            (string) $productEvent->reason,
            $changedFields,
            (string) $productEvent->change_digest,
            (string) $productEvent->request_id,
            (string) $productEvent->created_at,
        );

        return hash_equals($detailsHash, $expectedDetailsHash);
    }

    private function verifyReleaseChannelDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;
        $detailsReferenceId = $row['details_ref_id'] ?? null;
        $detailsHash = $row['details_hash'] ?? null;
        $resourceId = $row['resource_id'] ?? null;
        $actorUserId = $row['actor_user_id'] ?? null;
        $tenantVendorAccountId = $row['tenant_vendor_account_id'] ?? null;
        $requestId = $row['request_id'] ?? null;
        $action = $row['action'] ?? null;

        if (
            ! is_string($detailsReferenceId)
            || ! is_string($detailsHash)
            || ! is_string($resourceId)
            || ! is_string($actorUserId)
            || ! is_string($tenantVendorAccountId)
            || ! is_string($requestId)
            || ! in_array($action, ['release_channel.created', 'release_channel.promoted'], true)
        ) {
            return false;
        }

        $event = DB::table('release_channel_events')
            ->join('release_channels', 'release_channels.id', '=', 'release_channel_events.release_channel_id')
            ->join('products', 'products.id', '=', 'release_channel_events.product_id')
            ->leftJoin('product_versions as previous_versions', 'previous_versions.id', '=', 'release_channel_events.previous_product_version_id')
            ->leftJoin('product_versions as promoted_versions', 'promoted_versions.id', '=', 'release_channel_events.product_version_id')
            ->where('release_channel_events.id', $detailsReferenceId)
            ->first([
                'release_channel_events.id',
                'release_channel_events.release_channel_id',
                'release_channel_events.product_id',
                'release_channel_events.vendor_account_id',
                'release_channel_events.actor_user_id',
                'release_channel_events.event_type',
                'release_channel_events.channel_version_number',
                'release_channel_events.previous_product_version_id',
                'release_channel_events.product_version_id',
                'release_channel_events.reason',
                'release_channel_events.request_id',
                'release_channel_events.created_at',
                'release_channels.product_id as channel_product_id',
                'release_channels.vendor_account_id as channel_vendor_account_id',
                'products.vendor_account_id as product_vendor_account_id',
                'previous_versions.product_id as previous_product_id',
                'promoted_versions.product_id as promoted_product_id',
                'promoted_versions.status as promoted_version_status',
            ]);

        $expectedEventType = $action === 'release_channel.created' ? 'created' : 'promoted';

        if (
            $event === null
            || $event->event_type !== $expectedEventType
            || (string) $event->release_channel_id !== $resourceId
            || (string) $event->channel_product_id !== (string) $event->product_id
            || (string) $event->vendor_account_id !== $tenantVendorAccountId
            || (string) $event->channel_vendor_account_id !== $tenantVendorAccountId
            || (string) $event->product_vendor_account_id !== $tenantVendorAccountId
            || (string) $event->actor_user_id !== $actorUserId
            || (string) $event->request_id !== $requestId
            || ($event->previous_product_version_id !== null && (string) $event->previous_product_id !== (string) $event->product_id)
            || ($event->product_version_id !== null && (
                (string) $event->promoted_product_id !== (string) $event->product_id
                || $event->promoted_version_status !== 'published'
            ))
            || ($expectedEventType === 'created' && ($event->previous_product_version_id !== null || $event->product_version_id !== null))
            || ($expectedEventType === 'promoted' && $event->product_version_id === null)
        ) {
            return false;
        }

        $expectedDetailsHash = $this->hasher->hashReleaseChannelEvent(
            (string) $event->id,
            (string) $event->release_channel_id,
            (string) $event->product_id,
            (string) $event->vendor_account_id,
            (string) $event->actor_user_id,
            (string) $event->event_type,
            (int) $event->channel_version_number,
            $event->previous_product_version_id === null ? null : (string) $event->previous_product_version_id,
            $event->product_version_id === null ? null : (string) $event->product_version_id,
            (string) $event->reason,
            (string) $event->request_id,
            (string) $event->created_at,
        );

        return hash_equals($detailsHash, $expectedDetailsHash);
    }

    private function verifyProductVersionDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;
        $detailsReferenceId = $row['details_ref_id'] ?? null;
        $detailsHash = $row['details_hash'] ?? null;
        $resourceId = $row['resource_id'] ?? null;
        $actorUserId = $row['actor_user_id'] ?? null;
        $tenantVendorAccountId = $row['tenant_vendor_account_id'] ?? null;
        $requestId = $row['request_id'] ?? null;
        $action = $row['action'] ?? null;

        if (
            ! is_string($detailsReferenceId)
            || ! is_string($detailsHash)
            || ! is_string($resourceId)
            || ! is_string($actorUserId)
            || ! is_string($tenantVendorAccountId)
            || ! is_string($requestId)
            || ! in_array($action, ['product_version.created', 'product_version.updated', 'product_version.published'], true)
        ) {
            return false;
        }

        $versionEvent = DB::table('product_version_events')
            ->join('product_versions', 'product_versions.id', '=', 'product_version_events.product_version_id')
            ->join('products', 'products.id', '=', 'product_version_events.product_id')
            ->where('product_version_events.id', $detailsReferenceId)
            ->first([
                'product_version_events.id',
                'product_version_events.product_version_id',
                'product_version_events.product_id',
                'product_version_events.vendor_account_id',
                'product_version_events.actor_user_id',
                'product_version_events.event_type',
                'product_version_events.reason',
                'product_version_events.changed_fields',
                'product_version_events.change_digest',
                'product_version_events.request_id',
                'product_version_events.created_at',
                'product_versions.product_id as version_product_id',
                'product_versions.vendor_account_id as version_vendor_account_id',
                'products.vendor_account_id as product_vendor_account_id',
            ]);

        $expectedEventType = match ($action) {
            'product_version.created' => 'created',
            'product_version.updated' => 'updated',
            default => 'published',
        };

        if (
            $versionEvent === null
            || $versionEvent->event_type !== $expectedEventType
            || (string) $versionEvent->product_version_id !== $resourceId
            || (string) $versionEvent->version_product_id !== (string) $versionEvent->product_id
            || (string) $versionEvent->product_id !== (string) $versionEvent->version_product_id
            || (string) $versionEvent->vendor_account_id !== $tenantVendorAccountId
            || (string) $versionEvent->version_vendor_account_id !== $tenantVendorAccountId
            || (string) $versionEvent->product_vendor_account_id !== $tenantVendorAccountId
            || (string) $versionEvent->actor_user_id !== $actorUserId
            || (string) $versionEvent->request_id !== $requestId
            || ! is_string($versionEvent->change_digest)
            || preg_match('/\A[a-f0-9]{64}\z/D', $versionEvent->change_digest) !== 1
        ) {
            return false;
        }

        $changedFields = json_decode((string) $versionEvent->changed_fields, true);
        if (! is_array($changedFields) || ! array_is_list($changedFields)) {
            return false;
        }

        $expectedDetailsHash = $this->hasher->hashProductVersionEvent(
            (string) $versionEvent->id,
            (string) $versionEvent->product_version_id,
            (string) $versionEvent->product_id,
            (string) $versionEvent->vendor_account_id,
            (string) $versionEvent->actor_user_id,
            (string) $versionEvent->event_type,
            (string) $versionEvent->reason,
            $changedFields,
            (string) $versionEvent->change_digest,
            (string) $versionEvent->request_id,
            (string) $versionEvent->created_at,
        );

        return hash_equals($detailsHash, $expectedDetailsHash);
    }

    private function verifyProductAssignmentDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;
        $detailsReferenceId = $row['details_ref_id'] ?? null;
        $detailsHash = $row['details_hash'] ?? null;
        $resourceId = $row['resource_id'] ?? null;
        $actorUserId = $row['actor_user_id'] ?? null;
        $tenantVendorAccountId = $row['tenant_vendor_account_id'] ?? null;
        $requestId = $row['request_id'] ?? null;
        $action = $row['action'] ?? null;

        if (
            ! is_string($detailsReferenceId)
            || ! is_string($detailsHash)
            || ! is_string($resourceId)
            || ! is_string($actorUserId)
            || ! is_string($tenantVendorAccountId)
            || ! is_string($requestId)
            || ! in_array($action, ['product.assignment_granted', 'product.assignment_revoked'], true)
        ) {
            return false;
        }

        $assignmentEvent = DB::table('product_assignment_events')
            ->join('product_assignments', 'product_assignments.id', '=', 'product_assignment_events.assignment_id')
            ->join('products', 'products.id', '=', 'product_assignment_events.product_id')
            ->join('vendor_memberships', 'vendor_memberships.id', '=', 'product_assignment_events.target_vendor_membership_id')
            ->where('product_assignment_events.id', $detailsReferenceId)
            ->first([
                'product_assignment_events.id',
                'product_assignment_events.assignment_id',
                'product_assignment_events.product_id',
                'product_assignment_events.vendor_account_id',
                'product_assignment_events.actor_user_id',
                'product_assignment_events.target_vendor_membership_id',
                'product_assignment_events.event_type',
                'product_assignment_events.reason',
                'product_assignment_events.request_id',
                'product_assignment_events.created_at',
                'product_assignments.product_id as assignment_product_id',
                'product_assignments.vendor_account_id as assignment_vendor_account_id',
                'product_assignments.vendor_membership_id as assignment_membership_id',
                'products.vendor_account_id as product_vendor_account_id',
                'vendor_memberships.vendor_account_id as membership_vendor_account_id',
            ]);

        $expectedEventType = $action === 'product.assignment_granted' ? 'granted' : 'revoked';

        if (
            $assignmentEvent === null
            || $assignmentEvent->event_type !== $expectedEventType
            || (string) $assignmentEvent->product_id !== $resourceId
            || (string) $assignmentEvent->assignment_product_id !== $resourceId
            || (string) $assignmentEvent->vendor_account_id !== $tenantVendorAccountId
            || (string) $assignmentEvent->assignment_vendor_account_id !== $tenantVendorAccountId
            || (string) $assignmentEvent->product_vendor_account_id !== $tenantVendorAccountId
            || (string) $assignmentEvent->membership_vendor_account_id !== $tenantVendorAccountId
            || (string) $assignmentEvent->assignment_membership_id !== (string) $assignmentEvent->target_vendor_membership_id
            || (string) $assignmentEvent->actor_user_id !== $actorUserId
            || (string) $assignmentEvent->request_id !== $requestId
        ) {
            return false;
        }

        $expectedDetailsHash = $this->hasher->hashProductAssignmentEvent(
            (string) $assignmentEvent->id,
            (string) $assignmentEvent->assignment_id,
            (string) $assignmentEvent->product_id,
            (string) $assignmentEvent->vendor_account_id,
            (string) $assignmentEvent->actor_user_id,
            (string) $assignmentEvent->target_vendor_membership_id,
            (string) $assignmentEvent->event_type,
            (string) $assignmentEvent->reason,
            (string) $assignmentEvent->request_id,
            (string) $assignmentEvent->created_at,
        );

        return hash_equals($detailsHash, $expectedDetailsHash);
    }

    private function verifyPlanDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;
        $detailsReferenceId = $row['details_ref_id'] ?? null;
        $detailsHash = $row['details_hash'] ?? null;
        $resourceId = $row['resource_id'] ?? null;
        $actorUserId = $row['actor_user_id'] ?? null;
        $tenantVendorAccountId = $row['tenant_vendor_account_id'] ?? null;
        $requestId = $row['request_id'] ?? null;
        $action = $row['action'] ?? null;

        if (
            ! is_string($detailsReferenceId)
            || ! is_string($detailsHash)
            || ! is_string($resourceId)
            || ! is_string($actorUserId)
            || ! is_string($tenantVendorAccountId)
            || ! is_string($requestId)
            || ! in_array($action, [
                'plan.created',
                'plan_version.created',
                'plan_version.updated',
                'plan_version.published',
            ], true)
        ) {
            return false;
        }

        $event = DB::table('plan_events')
            ->join('plans', 'plans.id', '=', 'plan_events.plan_id')
            ->join('products', 'products.id', '=', 'plan_events.product_id')
            ->leftJoin('plan_versions', 'plan_versions.id', '=', 'plan_events.plan_version_id')
            ->where('plan_events.id', $detailsReferenceId)
            ->first([
                'plan_events.id',
                'plan_events.plan_id',
                'plan_events.plan_version_id',
                'plan_events.product_id',
                'plan_events.vendor_account_id',
                'plan_events.actor_user_id',
                'plan_events.event_type',
                'plan_events.reason',
                'plan_events.request_id',
                'plan_events.event_payload',
                'plan_events.created_at',
                'plans.product_id as plan_product_id',
                'plans.vendor_account_id as plan_vendor_account_id',
                'products.vendor_account_id as product_vendor_account_id',
                'plan_versions.plan_id as version_plan_id',
                'plan_versions.product_id as version_product_id',
                'plan_versions.vendor_account_id as version_vendor_account_id',
            ]);

        if ($event === null) {
            return false;
        }

        $expectedEventType = match ($action) {
            'plan.created' => 'created',
            'plan_version.created' => 'version_created',
            'plan_version.updated' => 'version_updated',
            default => 'version_published',
        };
        $expectedResourceId = $action === 'plan.created'
            ? (string) $event->plan_id
            : (string) ($event->plan_version_id ?? '');

        if (
            $event->event_type !== $expectedEventType
            || $expectedResourceId !== $resourceId
            || (string) $event->plan_product_id !== (string) $event->product_id
            || (string) $event->plan_vendor_account_id !== $tenantVendorAccountId
            || (string) $event->vendor_account_id !== $tenantVendorAccountId
            || (string) $event->product_vendor_account_id !== $tenantVendorAccountId
            || (string) $event->actor_user_id !== $actorUserId
            || (string) $event->request_id !== $requestId
            || ($action === 'plan.created' && $event->plan_version_id !== null)
            || ($action !== 'plan.created' && (
                $event->plan_version_id === null
                || (string) $event->version_plan_id !== (string) $event->plan_id
                || (string) $event->version_product_id !== (string) $event->product_id
                || (string) $event->version_vendor_account_id !== $tenantVendorAccountId
            ))
        ) {
            return false;
        }

        $payload = json_decode((string) $event->event_payload, true);
        if (! is_array($payload) || array_is_list($payload)) {
            return false;
        }

        $expectedDetailsHash = $this->hasher->hashPlanEvent(
            (string) $event->id,
            (string) $event->plan_id,
            $event->plan_version_id === null ? null : (string) $event->plan_version_id,
            (string) $event->product_id,
            (string) $event->vendor_account_id,
            (string) $event->actor_user_id,
            (string) $event->event_type,
            (string) $event->reason,
            (string) $event->request_id,
            $payload,
            (string) $event->created_at,
        );

        return hash_equals($detailsHash, $expectedDetailsHash);
    }

    private function verifyCustomerProfileDetails(object $auditEvent): bool
    {
        $row = (array) $auditEvent;
        $action = $row['action'] ?? null;
        $detailsReferenceId = $row['details_ref_id'] ?? null;
        $detailsHash = $row['details_hash'] ?? null;
        $resourceId = $row['resource_id'] ?? null;
        $actorUserId = $row['actor_user_id'] ?? null;
        $tenantVendorAccountId = $row['tenant_vendor_account_id'] ?? null;
        $requestId = $row['request_id'] ?? null;

        if (
            ! is_string($action)
            || ! in_array($action, [
                'customer_organization.created',
                'customer_organization.profile_updated',
            ], true)
            || ! is_string($detailsReferenceId)
            || ! is_string($detailsHash)
            || ! is_string($resourceId)
            || ! is_string($actorUserId)
            || ! is_string($tenantVendorAccountId)
            || ! is_string($requestId)
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
            ->where('customer_organization_profile_events.id', $detailsReferenceId)
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

        $expectedEventType = $action === 'customer_organization.created'
            ? 'created'
            : 'profile_updated';

        if (
            $profileEvent === null
            || $profileEvent->event_type !== $expectedEventType
            || (string) $profileEvent->customer_organization_id !== $resourceId
            || (string) $profileEvent->actor_user_id !== $actorUserId
            || (string) $profileEvent->vendor_account_id !== $tenantVendorAccountId
            || (string) $profileEvent->request_id !== $requestId
            || ! is_string($profileEvent->change_digest)
            || preg_match('/\A[a-f0-9]{64}\z/D', $profileEvent->change_digest) !== 1
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

        return hash_equals($detailsHash, $expectedDetailsHash);
    }
}
