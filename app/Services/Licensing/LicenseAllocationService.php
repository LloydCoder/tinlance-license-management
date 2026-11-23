<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Exceptions\LicenseAllocationConflictException;
use App\Models\License;
use App\Models\LicenseAllocation;
use App\Models\LicenseAllocationEvent;
use App\Models\LicenseEvent;
use App\Models\PlanVersion;
use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformSuperAdminAuthorization;
use Tlms\Domain\Licensing\DomainName;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class LicenseAllocationService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private LicenseKeyMaterialProvider $keyMaterial,
    ) {}

    /**
     * @return array{allocation: LicenseAllocation, replayed: bool}
     */
    public function allocate(
        User $actor,
        License $license,
        string $allocationType,
        string $subjectIdentifier,
        string $reason,
        string $requestId,
    ): array {
        $allocationType = trim($allocationType);
        $subjectIdentifier = trim($subjectIdentifier);
        $reason = trim($reason);
        $requestId = trim($requestId);

        if (
            ! in_array($allocationType, ['site', 'seat', 'user', 'domain'], true)
            || ($allocationType !== 'domain' && preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/D', $subjectIdentifier) !== 1)
            || mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) !== 1
        ) {
            throw new InvalidArgumentException('Licence allocation request metadata is invalid.');
        }

        $vendorId = (string) $license->vendor_account_id;
        $normalizedSubject = $allocationType === 'domain'
            ? DomainName::normalize($subjectIdentifier)
            : mb_strtolower($subjectIdentifier, 'UTF-8');

        return DB::transaction(function () use (
            $actor,
            $license,
            $allocationType,
            $reason,
            $requestId,
            $vendorId,
            $normalizedSubject,
        ): array {
            $vendor = VendorAccount::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();
            /** @var License $lockedLicense */
            $lockedLicense = License::query()->whereKey($license->getKey())->lockForUpdate()->firstOrFail();
            /** @var Product $product */
            $product = Product::query()->whereKey($lockedLicense->product_id)->firstOrFail();
            /** @var PlanVersion $planVersion */
            $planVersion = PlanVersion::query()
                ->whereKey($lockedLicense->plan_version_id)
                ->where('vendor_account_id', $vendorId)
                ->where('product_id', $product->getKey())
                ->where('status', 'published')
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeActor($actor, $vendor, $product);

            if ((string) $lockedLicense->vendor_account_id !== $vendorId) {
                throw new AuthorizationException('The licence does not belong to the authorized vendor.');
            }

            $fingerprintKeyId = (string) $lockedLicense->pepper_key_id;
            $allocationKey = hash_hkdf(
                'sha256',
                $this->keyMaterial->pepperMaterial($fingerprintKeyId),
                32,
                'tlms:license-allocation-fingerprint:v1',
            );
            $subjectHash = hash_hmac('sha256', $normalizedSubject, $allocationKey);

            $existingEvent = LicenseAllocationEvent::query()
                ->where('vendor_account_id', $vendorId)
                ->where('request_id', $requestId)
                ->first();

            if ($existingEvent instanceof LicenseAllocationEvent) {
                $payload = json_decode((string) $existingEvent->getRawOriginal('event_payload'), true);
                $allocationId = is_array($payload) ? ($payload['allocation_id'] ?? null) : null;
                $payloadIsValid = is_array($payload)
                    && hash_equals((string) $existingEvent->payload_sha256, $this->payloadHash($payload));

                if (
                    $existingEvent->event_type !== 'allocated'
                    || (string) $existingEvent->actor_user_id !== (string) $actor->getKey()
                    || (string) $existingEvent->license_id !== (string) $lockedLicense->getKey()
                    || (string) $existingEvent->reason !== $reason
                    || ! is_string($allocationId)
                    || ($payload['allocation_type'] ?? null) !== $allocationType
                    || ($payload['subject_hash'] ?? null) !== $subjectHash
                    || ! $payloadIsValid
                ) {
                    throw new LicenseAllocationConflictException('The request identifier was already used for a different allocation operation.');
                }

                $existingAllocation = LicenseAllocation::query()
                    ->whereKey($allocationId)
                    ->where('license_id', $lockedLicense->getKey())
                    ->firstOrFail();

                return ['allocation' => $existingAllocation, 'replayed' => true];
            }

            if (LicenseEvent::query()
                ->where('vendor_account_id', $vendorId)
                ->where('request_id', $requestId)
                ->exists()) {
                throw new LicenseAllocationConflictException('The request identifier was already used by another licence operation.');
            }

            $this->assertAllocatable($lockedLicense);
            $limitKey = match ($allocationType) {
                'site' => 'sites',
                'seat' => 'seats',
                'user' => 'users',
                'domain' => 'domains',
            };
            $entitlements = $planVersion->entitlements;
            $limit = $entitlements[$limitKey] ?? null;

            if (! is_int($limit) || $limit < 1) {
                throw new LicenseAllocationConflictException('The published plan does not grant this allocation type.');
            }

            /** @var LicenseAllocation|null $allocation */
            $allocation = LicenseAllocation::query()
                ->where('license_id', $lockedLicense->getKey())
                ->where('allocation_type', $allocationType)
                ->where('subject_hash', $subjectHash)
                ->lockForUpdate()
                ->first();

            if ($allocation instanceof LicenseAllocation && $allocation->status === 'active') {
                throw new LicenseAllocationConflictException('This subject already has an active licence allocation.');
            }

            $activeCount = LicenseAllocation::query()
                ->where('license_id', $lockedLicense->getKey())
                ->where('allocation_type', $allocationType)
                ->where('status', 'active')
                ->count();

            if ($activeCount >= $limit) {
                throw new LicenseAllocationConflictException('The published plan allocation limit has been reached.');
            }

            if ($allocation instanceof LicenseAllocation) {
                $nextVersion = (int) $allocation->version + 1;
                $allocation->fill(['status' => 'active', 'version' => $nextVersion])->save();
            } else {
                $allocation = LicenseAllocation::query()->create([
                    'license_id' => $lockedLicense->getKey(),
                    'vendor_account_id' => $vendorId,
                    'product_id' => $product->getKey(),
                    'allocation_type' => $allocationType,
                    'subject_hash' => $subjectHash,
                    'fingerprint_key_id' => $fingerprintKeyId,
                    'status' => 'active',
                    'version' => 1,
                    'created_by_user_id' => $actor->getKey(),
                ]);
                $nextVersion = 1;
            }

            $eventPayload = [
                'allocation_id' => (string) $allocation->getKey(),
                'aggregate_version' => $nextVersion,
                'allocation_type' => $allocationType,
                'reason' => $reason,
                'status' => 'active',
                'subject_hash' => $subjectHash,
            ];
            ksort($eventPayload, SORT_STRING);

            LicenseAllocationEvent::query()->create([
                'allocation_id' => $allocation->getKey(),
                'license_id' => $lockedLicense->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $product->getKey(),
                'actor_user_id' => $actor->getKey(),
                'event_type' => 'allocated',
                'reason' => $reason,
                'request_id' => $requestId,
                'payload_sha256' => $this->payloadHash($eventPayload),
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            return ['allocation' => $allocation->refresh(), 'replayed' => false];
        }, 3);
    }

    /**
     * @return array{allocation: LicenseAllocation, replayed: bool}
     */
    public function release(
        User $actor,
        License $license,
        LicenseAllocation $allocation,
        string $reason,
        string $requestId,
    ): array {
        $reason = trim($reason);
        $requestId = trim($requestId);

        if (
            mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) !== 1
        ) {
            throw new InvalidArgumentException('Licence allocation release metadata is invalid.');
        }

        $vendorId = (string) $license->vendor_account_id;

        return DB::transaction(function () use ($actor, $license, $allocation, $reason, $requestId, $vendorId): array {
            $vendor = VendorAccount::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();
            /** @var License $lockedLicense */
            $lockedLicense = License::query()->whereKey($license->getKey())->lockForUpdate()->firstOrFail();
            /** @var LicenseAllocation $lockedAllocation */
            $lockedAllocation = LicenseAllocation::query()
                ->whereKey($allocation->getKey())
                ->where('license_id', $lockedLicense->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            /** @var Product $product */
            $product = Product::query()->whereKey($lockedLicense->product_id)->firstOrFail();

            $this->authorizeActor($actor, $vendor, $product);

            if (
                (string) $lockedLicense->vendor_account_id !== $vendorId
                || (string) $lockedAllocation->vendor_account_id !== $vendorId
                || (string) $lockedAllocation->product_id !== (string) $product->getKey()
            ) {
                throw new AuthorizationException('The allocation does not belong to the authorized licence tenant.');
            }

            $existingEvent = LicenseAllocationEvent::query()
                ->where('vendor_account_id', $vendorId)
                ->where('request_id', $requestId)
                ->first();

            if ($existingEvent instanceof LicenseAllocationEvent) {
                $payload = json_decode((string) $existingEvent->getRawOriginal('event_payload'), true);
                $payloadIsValid = is_array($payload)
                    && hash_equals((string) $existingEvent->payload_sha256, $this->payloadHash($payload));

                if (
                    $existingEvent->event_type !== 'released'
                    || (string) $existingEvent->allocation_id !== (string) $lockedAllocation->getKey()
                    || (string) $existingEvent->actor_user_id !== (string) $actor->getKey()
                    || (string) $existingEvent->license_id !== (string) $lockedLicense->getKey()
                    || (string) $existingEvent->reason !== $reason
                    || ! $payloadIsValid
                ) {
                    throw new LicenseAllocationConflictException('The request identifier was already used for a different allocation operation.');
                }

                return ['allocation' => $lockedAllocation->refresh(), 'replayed' => true];
            }

            if (LicenseEvent::query()
                ->where('vendor_account_id', $vendorId)
                ->where('request_id', $requestId)
                ->exists()) {
                throw new LicenseAllocationConflictException('The request identifier was already used by another licence operation.');
            }

            if ($lockedAllocation->status !== 'active') {
                throw new LicenseAllocationConflictException('Only active licence allocations can be released.');
            }

            $nextVersion = (int) $lockedAllocation->version + 1;
            $lockedAllocation->fill(['status' => 'released', 'version' => $nextVersion])->save();

            $eventPayload = [
                'allocation_id' => (string) $lockedAllocation->getKey(),
                'aggregate_version' => $nextVersion,
                'allocation_type' => (string) $lockedAllocation->allocation_type,
                'reason' => $reason,
                'status' => 'released',
                'subject_hash' => (string) $lockedAllocation->subject_hash,
            ];
            ksort($eventPayload, SORT_STRING);

            LicenseAllocationEvent::query()->create([
                'allocation_id' => $lockedAllocation->getKey(),
                'license_id' => $lockedLicense->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $product->getKey(),
                'actor_user_id' => $actor->getKey(),
                'event_type' => 'released',
                'reason' => $reason,
                'request_id' => $requestId,
                'payload_sha256' => $this->payloadHash($eventPayload),
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            return ['allocation' => $lockedAllocation->refresh(), 'replayed' => false];
        }, 3);
    }

    private function assertAllocatable(License $license): void
    {
        if ($license->status !== 'active') {
            throw new LicenseAllocationConflictException('Only active licences may reserve allocations.');
        }

        if ($license->starts_at !== null && $license->starts_at->isFuture()) {
            throw new LicenseAllocationConflictException('A licence cannot allocate resources before its start time.');
        }

        $effectiveExpiry = $license->grace_until ?? $license->expires_at;
        if ($effectiveExpiry !== null && $effectiveExpiry->lessThanOrEqualTo(now())) {
            throw new LicenseAllocationConflictException('An expired licence cannot reserve allocations.');
        }
    }

    private function authorizeActor(User $actor, VendorAccount $vendor, Product $product): void
    {
        if (
            $vendor->status !== 'active'
            || $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'licences.mutate')
        ) {
            throw new AuthorizationException('The actor may not mutate licence allocations for this vendor.');
        }

        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->with('roles')
            ->first();

        if (! $membership instanceof VendorMembership) {
            throw new AuthorizationException('An active vendor membership is required to mutate licence allocations.');
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
            throw new AuthorizationException('The actor must be assigned to this product to mutate its licence allocations.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        ksort($payload, SORT_STRING);

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
