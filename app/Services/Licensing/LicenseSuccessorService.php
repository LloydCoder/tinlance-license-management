<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Exceptions\LicenseSuccessorConflictException;
use App\Models\CustomerOrganization;
use App\Models\License;
use App\Models\LicenseAllocation;
use App\Models\LicenseAllocationEvent;
use App\Models\LicenseEvent;
use App\Models\LicenseIssuanceRequest;
use App\Models\LicenseSuccessorRelation;
use App\Models\LicenseUsageEvent;
use App\Models\PlanVersion;
use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformSuperAdminAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class LicenseSuccessorService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private LicenseIssuanceService $issuance,
    ) {}

    /**
     * @return array{relation: LicenseSuccessorRelation, successor: License, issuance_request: LicenseIssuanceRequest, replayed: bool, secret_available: bool}
     */
    public function create(
        User $actor,
        License $source,
        string $relationType,
        ?string $destinationCustomerId,
        string $reason,
        string $idempotencyKey,
        string $requestId,
    ): array {
        $relationType = trim($relationType);
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);
        $requestId = trim($requestId);

        if (
            ! in_array($relationType, ['transfer', 'replacement'], true)
            || mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
            || preg_match('/\A[A-Za-z0-9._~-]{32,128}\z/D', $idempotencyKey) !== 1
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) !== 1
            || ($relationType === 'transfer' && (! is_string($destinationCustomerId) || trim($destinationCustomerId) === ''))
            || ($relationType === 'replacement' && $destinationCustomerId !== null)
        ) {
            throw new InvalidArgumentException('Licence successor request metadata is invalid.');
        }

        $vendorId = (string) $source->vendor_account_id;
        $actorId = (string) $actor->getKey();
        $idempotencyHash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use (
            $actor,
            $source,
            $relationType,
            $destinationCustomerId,
            $reason,
            $requestId,
            $vendorId,
            $actorId,
            $idempotencyHash,
        ): array {
            $vendor = VendorAccount::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();
            /** @var License $lockedSource */
            $lockedSource = License::query()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();
            /** @var Product $product */
            $product = Product::query()->whereKey($lockedSource->product_id)->firstOrFail();
            /** @var CustomerOrganization $sourceCustomer */
            $sourceCustomer = CustomerOrganization::query()
                ->whereKey($lockedSource->customer_organization_id)
                ->where('vendor_account_id', $vendorId)
                ->firstOrFail();
            /** @var PlanVersion $planVersion */
            $planVersion = PlanVersion::query()
                ->whereKey($lockedSource->plan_version_id)
                ->where('vendor_account_id', $vendorId)
                ->where('product_id', $product->getKey())
                ->where('status', 'published')
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeActor($actor, $vendor, $product);

            $destinationId = $relationType === 'replacement'
                ? (string) $sourceCustomer->getKey()
                : (string) $destinationCustomerId;
            /** @var CustomerOrganization $destination */
            $destination = CustomerOrganization::query()
                ->whereKey($destinationId)
                ->where('vendor_account_id', $vendorId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            $fingerprintPayload = [
                'destination_customer_organization_id' => $destinationId,
                'license_id' => (string) $lockedSource->getKey(),
                'plan_version_id' => (string) $planVersion->getKey(),
                'product_id' => (string) $product->getKey(),
                'reason' => $reason,
                'relation_type' => $relationType,
                'source_expires_at' => $lockedSource->expires_at?->toISOString(),
                'source_grace_until' => $lockedSource->grace_until?->toISOString(),
                'source_starts_at' => $lockedSource->starts_at?->toISOString(),
            ];
            $fingerprint = $this->payloadHash($fingerprintPayload);

            $existing = LicenseSuccessorRelation::query()
                ->where('vendor_account_id', $vendorId)
                ->where(static function ($query) use ($requestId, $idempotencyHash): void {
                    $query->where('request_id', $requestId)
                        ->orWhere('idempotency_key_sha256', $idempotencyHash);
                })
                ->first();

            if ($existing instanceof LicenseSuccessorRelation) {
                if (
                    (string) $existing->actor_user_id !== $actorId
                    || ! hash_equals((string) $existing->request_fingerprint_sha256, $fingerprint)
                ) {
                    throw new LicenseSuccessorConflictException('The successor request identifier or idempotency key was already used for a different operation.');
                }

                return $this->existingResult($existing, true);
            }

            if (
                LicenseEvent::query()->where('vendor_account_id', $vendorId)->where('request_id', $requestId)->exists()
                || LicenseAllocationEvent::query()->where('vendor_account_id', $vendorId)->where('request_id', $requestId)->exists()
                || LicenseUsageEvent::query()->where('vendor_account_id', $vendorId)->where('request_id', $requestId)->exists()
            ) {
                throw new LicenseSuccessorConflictException('The request identifier was already used by another licence operation.');
            }

            if ($lockedSource->status !== 'active') {
                throw new LicenseSuccessorConflictException('Only active licences can be transferred or replaced.');
            }

            if ($lockedSource->starts_at !== null && $lockedSource->starts_at->isFuture()) {
                throw new LicenseSuccessorConflictException('A licence cannot be transferred or replaced before its start time.');
            }

            $effectiveExpiry = $lockedSource->grace_until ?? $lockedSource->expires_at;
            if ($effectiveExpiry !== null && $effectiveExpiry->lessThanOrEqualTo(now())) {
                throw new LicenseSuccessorConflictException('An expired licence cannot be transferred or replaced.');
            }

            if ($relationType === 'transfer' && (string) $destination->getKey() === (string) $sourceCustomer->getKey()) {
                throw new LicenseSuccessorConflictException('A transfer must target a different customer organization.');
            }

            $successorIdempotencyKey = self::base64Url(hash(
                'sha256',
                'tlms:license-successor:v1:'.$idempotencyHash,
                true,
            ));
            $successorRequestId = 'successor-issue:'.hash('sha256', $vendorId.':'.$requestId);

            $issued = $this->issuance->issue(
                $actor,
                $product,
                $destination,
                $planVersion,
                $lockedSource->starts_at?->toISOString(),
                $reason,
                $successorIdempotencyKey,
                $successorRequestId,
                $lockedSource->expires_at?->toISOString(),
                $lockedSource->grace_until?->toISOString(),
            );

            /** @var License $successor */
            $successor = $issued['license'];

            $activeAllocations = LicenseAllocation::query()
                ->where('license_id', $lockedSource->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            foreach ($activeAllocations as $allocation) {
                $allocationRequestId = 'successor-release:'.hash(
                    'sha256',
                    $requestId.':'.(string) $allocation->getKey(),
                );
                if (LicenseAllocationEvent::query()
                    ->where('vendor_account_id', $vendorId)
                    ->where('request_id', $allocationRequestId)
                    ->exists()) {
                    throw new LicenseSuccessorConflictException('A reserved allocation-release request identifier is already in use.');
                }

                $allocationVersion = (int) $allocation->version + 1;
                $allocation->fill([
                    'status' => 'released',
                    'version' => $allocationVersion,
                ])->save();

                $allocationPayload = [
                    'allocation_id' => (string) $allocation->getKey(),
                    'aggregate_version' => $allocationVersion,
                    'allocation_type' => (string) $allocation->allocation_type,
                    'reason' => $reason,
                    'status' => 'released',
                    'subject_hash' => (string) $allocation->subject_hash,
                ];
                ksort($allocationPayload, SORT_STRING);

                LicenseAllocationEvent::query()->create([
                    'allocation_id' => $allocation->getKey(),
                    'license_id' => $lockedSource->getKey(),
                    'vendor_account_id' => $vendorId,
                    'product_id' => $product->getKey(),
                    'actor_user_id' => $actorId,
                    'event_type' => 'released',
                    'reason' => $reason,
                    'request_id' => $allocationRequestId,
                    'payload_sha256' => $this->payloadHash($allocationPayload),
                    'event_payload' => $allocationPayload,
                    'created_at' => now(),
                ]);
            }

            $sourceVersion = (int) $lockedSource->version + 1;
            $lockedSource->fill([
                'status' => 'revoked',
                'version' => $sourceVersion,
            ])->save();

            $eventPayload = [
                'aggregate_version' => $sourceVersion,
                'destination_customer_organization_id' => $destinationId,
                'relation_type' => $relationType,
                'source_customer_organization_id' => (string) $sourceCustomer->getKey(),
                'source_license_id' => (string) $lockedSource->getKey(),
                'successor_license_id' => (string) $successor->getKey(),
            ];
            ksort($eventPayload, SORT_STRING);

            $relation = LicenseSuccessorRelation::query()->create([
                'source_license_id' => $lockedSource->getKey(),
                'successor_license_id' => $successor->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $product->getKey(),
                'source_customer_organization_id' => $sourceCustomer->getKey(),
                'destination_customer_organization_id' => $destination->getKey(),
                'actor_user_id' => $actorId,
                'relation_type' => $relationType,
                'reason' => $reason,
                'idempotency_key_sha256' => $idempotencyHash,
                'request_fingerprint_sha256' => $fingerprint,
                'request_id' => $requestId,
                'payload_sha256' => $this->payloadHash($eventPayload),
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            LicenseEvent::query()->create([
                'license_id' => $lockedSource->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $product->getKey(),
                'actor_type' => 'user',
                'actor_user_id' => $actorId,
                'event_type' => $relationType === 'transfer' ? 'transferred' : 'replaced',
                'from_status' => 'active',
                'to_status' => 'revoked',
                'reason' => $reason,
                'request_id' => $requestId,
                'payload_sha256' => $this->payloadHash($eventPayload),
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            return [
                'relation' => $relation,
                'successor' => $successor->refresh(),
                'issuance_request' => $issued['issuance_request'],
                'replayed' => false,
                'secret_available' => $issued['secret_available'],
            ];
        }, 3);
    }

    /**
     * @return array{relation: LicenseSuccessorRelation, successor: License, issuance_request: LicenseIssuanceRequest, replayed: bool, secret_available: bool}
     */
    private function existingResult(LicenseSuccessorRelation $relation, bool $replayed): array
    {
        /** @var License $successor */
        $successor = License::query()->whereKey($relation->successor_license_id)->firstOrFail();
        /** @var LicenseIssuanceRequest $issuanceRequest */
        $issuanceRequest = LicenseIssuanceRequest::query()
            ->where('license_id', $successor->getKey())
            ->where('operation', 'license.issue.v1')
            ->firstOrFail();

        return [
            'relation' => $relation,
            'successor' => $successor,
            'issuance_request' => $issuanceRequest,
            'replayed' => $replayed,
            'secret_available' => $issuanceRequest->status === 'completed'
                && $issuanceRequest->response_ciphertext !== null
                && $issuanceRequest->response_delivered_at === null
                && $issuanceRequest->expires_at !== null
                && $issuanceRequest->expires_at->isFuture(),
        ];
    }

    private function authorizeActor(User $actor, VendorAccount $vendor, Product $product): void
    {
        if (
            $vendor->status !== 'active'
            || $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'licences.mutate')
        ) {
            throw new AuthorizationException('The actor may not transfer or replace licences for this vendor.');
        }

        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->with('roles')
            ->first();

        if (! $membership instanceof VendorMembership) {
            throw new AuthorizationException('An active vendor membership is required for successor operations.');
        }

        $isVendorSuperAdmin = $membership->roles->contains(
            static fn (Role $role): bool => $role->scope === 'vendor' && $role->slug === 'super_admin',
        );

        if (! $isVendorSuperAdmin && ! ProductAssignment::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('product_id', $product->getKey())
            ->where('vendor_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->exists()) {
            throw new AuthorizationException('The actor must be assigned to this product to perform successor operations.');
        }
    }

    /** @param array<array-key, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        ksort($payload, SORT_STRING);

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
