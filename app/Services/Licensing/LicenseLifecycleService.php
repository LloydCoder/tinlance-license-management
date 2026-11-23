<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Exceptions\LicenseLifecycleConflictException;
use App\Models\License;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tlms\Domain\Licensing\LicenseLifecycle;

final readonly class LicenseLifecycleService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
    ) {}

    /**
     * @return array{license: License, replayed: bool}
     */
    public function transition(
        User $actor,
        License $license,
        string $toStatus,
        string $reason,
        string $requestId,
    ): array {
        $reason = trim($reason);
        $requestId = trim($requestId);

        if (
            ! in_array($toStatus, [LicenseLifecycle::ACTIVE, LicenseLifecycle::SUSPENDED, LicenseLifecycle::REVOKED], true)
            || mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) !== 1
        ) {
            throw new InvalidArgumentException('Licence lifecycle request metadata is invalid.');
        }

        $vendorId = (string) $license->vendor_account_id;

        return DB::transaction(function () use ($actor, $license, $toStatus, $reason, $requestId, $vendorId): array {
            $vendor = VendorAccount::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();
            /** @var License $lockedLicense */
            $lockedLicense = License::query()->whereKey($license->getKey())->lockForUpdate()->firstOrFail();
            /** @var Product $product */
            $product = Product::query()->whereKey($lockedLicense->product_id)->firstOrFail();

            $this->authorizeActor($actor, $vendor, $product);

            if ((string) $lockedLicense->vendor_account_id !== $vendorId) {
                throw new AuthorizationException('The licence does not belong to the authorized vendor.');
            }

            $existingEvent = LicenseEvent::query()
                ->where('vendor_account_id', $vendorId)
                ->where('request_id', $requestId)
                ->first();

            if ($existingEvent instanceof LicenseEvent) {
                $existingFrom = (string) $existingEvent->from_status;
                $expectedEventType = $this->eventType($existingFrom, $toStatus);
                $existingPayload = json_decode((string) $existingEvent->getRawOriginal('event_payload'), true);
                $versionValue = is_array($existingPayload)
                    ? ($existingPayload['aggregate_version'] ?? null)
                    : null;

                if (! is_numeric($versionValue) || (int) $versionValue < 1) {
                    throw new LicenseLifecycleConflictException('The existing lifecycle event payload is invalid.');
                }

                $existingAggregateVersion = (int) $versionValue;

                $expectedPayload = $this->eventPayload(
                    (string) $lockedLicense->getKey(),
                    $existingFrom,
                    $toStatus,
                    $existingAggregateVersion,
                    $reason,
                );
                $expectedHash = $this->payloadHash($expectedPayload);

                if (
                    (string) $existingEvent->license_id !== (string) $lockedLicense->getKey()
                    || (string) $existingEvent->actor_user_id !== (string) $actor->getKey()
                    || $existingEvent->event_type !== $expectedEventType
                    || $existingEvent->to_status !== $toStatus
                    || ! hash_equals((string) $existingEvent->payload_sha256, $expectedHash)
                ) {
                    throw new LicenseLifecycleConflictException('The request identifier was already used for a different licence lifecycle action.');
                }

                return ['license' => $lockedLicense->refresh(), 'replayed' => true];
            }

            $fromStatus = (string) $lockedLicense->status;
            try {
                LicenseLifecycle::assertTransition($fromStatus, $toStatus);
            } catch (InvalidArgumentException $exception) {
                throw new LicenseLifecycleConflictException($exception->getMessage(), previous: $exception);
            }

            if ($toStatus === LicenseLifecycle::ACTIVE) {
                $this->assertActivationWindow($lockedLicense);
            }

            $eventType = $this->eventType($fromStatus, $toStatus);
            $nextVersion = (int) $lockedLicense->version + 1;
            $eventPayload = $this->eventPayload(
                (string) $lockedLicense->getKey(),
                $fromStatus,
                $toStatus,
                $nextVersion,
                $reason,
            );
            $payloadHash = $this->payloadHash($eventPayload);

            $lockedLicense->fill([
                'status' => $toStatus,
                'version' => $nextVersion,
            ])->save();

            LicenseEvent::query()->create([
                'license_id' => $lockedLicense->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $product->getKey(),
                'actor_type' => 'user',
                'actor_user_id' => $actor->getKey(),
                'event_type' => $eventType,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => $reason,
                'request_id' => $requestId,
                'payload_sha256' => $payloadHash,
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            return ['license' => $lockedLicense->refresh(), 'replayed' => false];
        }, 3);
    }

    /**
     * @return array{license: License, replayed: bool}
     */
    public function renew(User $actor, License $license, string $reason, string $requestId): array
    {
        $reason = trim($reason);
        $requestId = trim($requestId);

        if (
            mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
            || preg_match('/\\A[A-Za-z0-9._:-]{1,128}\\z/D', $requestId) !== 1
        ) {
            throw new InvalidArgumentException('Licence renewal request metadata is invalid.');
        }

        $vendorId = (string) $license->vendor_account_id;

        return DB::transaction(function () use ($actor, $license, $reason, $requestId, $vendorId): array {
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

            $existingEvent = LicenseEvent::query()
                ->where('vendor_account_id', $vendorId)
                ->where('request_id', $requestId)
                ->first();

            if ($existingEvent instanceof LicenseEvent) {
                $existingPayload = $existingEvent->event_payload;
                $payloadIsValid = is_array($existingPayload)
                    && hash_equals(
                        (string) $existingEvent->payload_sha256,
                        $this->payloadHash($existingPayload),
                    );

                if (
                    $existingEvent->event_type !== 'renewed'
                    || (string) $existingEvent->license_id !== (string) $lockedLicense->getKey()
                    || (string) $existingEvent->actor_user_id !== (string) $actor->getKey()
                    || (string) $existingEvent->reason !== $reason
                    || ! $payloadIsValid
                ) {
                    throw new LicenseLifecycleConflictException('The request identifier was already used for a different licence operation.');
                }

                return ['license' => $lockedLicense->refresh(), 'replayed' => true];
            }

            if (! in_array((string) $lockedLicense->status, [LicenseLifecycle::ACTIVE, LicenseLifecycle::SUSPENDED], true)) {
                throw new LicenseLifecycleConflictException('Only active or suspended licences can be renewed.');
            }

            $effectiveExpiry = $lockedLicense->grace_until ?? $lockedLicense->expires_at;
            if ($effectiveExpiry === null || $effectiveExpiry->lessThanOrEqualTo(now())) {
                throw new LicenseLifecycleConflictException('A perpetual or expired licence cannot be renewed through this operation.');
            }

            $nextExpiry = match ($planVersion->billing_model) {
                'annual' => $effectiveExpiry->copy()->addYearNoOverflow(),
                'subscription' => match ($planVersion->billing_interval) {
                    'month' => $effectiveExpiry->copy()->addMonthNoOverflow(),
                    'year' => $effectiveExpiry->copy()->addYearNoOverflow(),
                    default => throw new LicenseLifecycleConflictException('The published subscription interval is invalid.'),
                },
                default => throw new LicenseLifecycleConflictException('Only annual and subscription licences support renewal.'),
            };

            $fromStatus = (string) $lockedLicense->status;
            $nextVersion = (int) $lockedLicense->version + 1;
            $eventPayload = [
                'aggregate_version' => $nextVersion,
                'billing_interval' => $planVersion->billing_interval,
                'billing_model' => $planVersion->billing_model,
                'license_id' => (string) $lockedLicense->getKey(),
                'new_expires_at' => $nextExpiry->toISOString(),
                'old_effective_expiry' => $effectiveExpiry->toISOString(),
                'reason' => $reason,
                'status' => $fromStatus,
            ];
            ksort($eventPayload, SORT_STRING);
            $payloadHash = $this->payloadHash($eventPayload);

            $lockedLicense->fill([
                'expires_at' => $nextExpiry,
                'grace_until' => null,
                'version' => $nextVersion,
            ])->save();

            LicenseEvent::query()->create([
                'license_id' => $lockedLicense->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $product->getKey(),
                'actor_type' => 'user',
                'actor_user_id' => $actor->getKey(),
                'event_type' => 'renewed',
                'from_status' => $fromStatus,
                'to_status' => $fromStatus,
                'reason' => $reason,
                'request_id' => $requestId,
                'payload_sha256' => $payloadHash,
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            return ['license' => $lockedLicense->refresh(), 'replayed' => false];
        }, 3);
    }

    private function authorizeActor(User $actor, VendorAccount $vendor, Product $product): void
    {
        if (
            $vendor->status !== 'active'
            || $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'licences.mutate')
        ) {
            throw new AuthorizationException('The actor may not mutate licences for this vendor.');
        }

        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->with('roles')
            ->first();

        if (! $membership instanceof VendorMembership) {
            throw new AuthorizationException('An active vendor membership is required to mutate licences.');
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
            throw new AuthorizationException('The actor must be assigned to this product to mutate its licence lifecycle.');
        }
    }

    private function assertActivationWindow(License $license): void
    {
        if ($license->starts_at !== null && $license->starts_at->isFuture()) {
            throw new LicenseLifecycleConflictException('A licence cannot be activated before its start time.');
        }

        $effectiveExpiry = $license->grace_until ?? $license->expires_at;
        if ($effectiveExpiry !== null && $effectiveExpiry->lessThanOrEqualTo(now())) {
            throw new LicenseLifecycleConflictException('An expired licence cannot be activated or reinstated.');
        }
    }

    private function eventType(string $fromStatus, string $toStatus): string
    {
        if ($toStatus === LicenseLifecycle::ACTIVE) {
            return $fromStatus === LicenseLifecycle::SUSPENDED ? 'reinstated' : 'activated';
        }

        if ($toStatus === LicenseLifecycle::SUSPENDED) {
            return 'suspended';
        }

        if ($toStatus === LicenseLifecycle::REVOKED) {
            return 'revoked';
        }

        throw new InvalidArgumentException('The requested licence lifecycle event is unsupported.');
    }

    /**
     * @return array{license_id: string, from_status: string, to_status: string, aggregate_version: int, reason: string}
     */
    private function eventPayload(
        string $licenseId,
        string $fromStatus,
        string $toStatus,
        int $aggregateVersion,
        string $reason,
    ): array {
        $payload = [
            'license_id' => $licenseId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'aggregate_version' => $aggregateVersion,
            'reason' => $reason,
        ];
        ksort($payload, SORT_STRING);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
