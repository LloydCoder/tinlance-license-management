<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Exceptions\LicenseUsageConflictException;
use App\Models\License;
use App\Models\LicenseAllocationEvent;
use App\Models\LicenseEvent;
use App\Models\LicenseUsageCounter;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class LicenseUsageService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
    ) {}

    /**
     * @return array{counter: LicenseUsageCounter, limit: int, period_start: Carbon, period_end: Carbon|null, replayed: bool}
     */
    public function consume(
        User $actor,
        License $license,
        string $metric,
        string $period,
        int $quantity,
        string $reason,
        string $requestId,
    ): array {
        $metric = trim($metric);
        $period = trim($period);
        $reason = trim($reason);
        $requestId = trim($requestId);

        if (
            preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $metric) !== 1
            || ! in_array($period, ['monthly', 'annual', 'lifetime'], true)
            || $quantity < 1
            || $quantity > 1000000000
            || mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) !== 1
        ) {
            throw new InvalidArgumentException('Licence usage request metadata is invalid.');
        }

        $vendorId = (string) $license->vendor_account_id;

        return DB::transaction(function () use (
            $actor,
            $license,
            $metric,
            $period,
            $quantity,
            $reason,
            $requestId,
            $vendorId,
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

            $this->assertUsable($lockedLicense);

            $entitlementKey = 'usage.'.$metric.'.'.$period;
            $limit = $planVersion->entitlements[$entitlementKey] ?? null;
            if (! is_int($limit) || $limit < 1) {
                throw new LicenseUsageConflictException('The published plan does not grant this usage metric and period.');
            }

            [$periodStart, $periodEnd] = $this->periodWindow($lockedLicense, $period);

            $existingEvent = LicenseUsageEvent::query()
                ->where('vendor_account_id', $vendorId)
                ->where('request_id', $requestId)
                ->first();

            if ($existingEvent instanceof LicenseUsageEvent) {
                $payload = json_decode((string) $existingEvent->getRawOriginal('event_payload'), true);
                $payloadIsValid = is_array($payload)
                    && hash_equals((string) $existingEvent->payload_sha256, $this->payloadHash($payload));

                if (
                    (string) $existingEvent->actor_user_id !== (string) $actor->getKey()
                    || (string) $existingEvent->license_id !== (string) $lockedLicense->getKey()
                    || $existingEvent->metric !== $metric
                    || $existingEvent->period !== $period
                    || (int) $existingEvent->quantity_delta !== $quantity
                    || (string) $existingEvent->reason !== $reason
                    || ! $payloadIsValid
                    || ($payload['period_start'] ?? null) !== $periodStart->toISOString()
                    || ($payload['period_end'] ?? null) !== $periodEnd?->toISOString()
                ) {
                    throw new LicenseUsageConflictException('The request identifier was already used for a different usage operation.');
                }

                /** @var LicenseUsageCounter $existingCounter */
                $existingCounter = LicenseUsageCounter::query()
                    ->whereKey($existingEvent->counter_id)
                    ->where('license_id', $lockedLicense->getKey())
                    ->firstOrFail();

                return [
                    'counter' => $existingCounter,
                    'limit' => $limit,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'replayed' => true,
                ];
            }

            if (
                LicenseEvent::query()->where('vendor_account_id', $vendorId)->where('request_id', $requestId)->exists()
                || LicenseAllocationEvent::query()->where('vendor_account_id', $vendorId)->where('request_id', $requestId)->exists()
            ) {
                throw new LicenseUsageConflictException('The request identifier was already used by another licence operation.');
            }

            /** @var LicenseUsageCounter|null $counter */
            $counter = LicenseUsageCounter::query()
                ->where('license_id', $lockedLicense->getKey())
                ->where('metric', $metric)
                ->where('period', $period)
                ->where('period_start', $periodStart)
                ->lockForUpdate()
                ->first();

            $currentQuantity = $counter instanceof LicenseUsageCounter ? (int) $counter->quantity : 0;
            $nextQuantity = $currentQuantity + $quantity;
            if ($nextQuantity > $limit) {
                throw new LicenseUsageConflictException('The published plan usage quota has been reached.');
            }

            if ($counter instanceof LicenseUsageCounter) {
                $counter->fill([
                    'quantity' => $nextQuantity,
                    'version' => (int) $counter->version + 1,
                ])->save();
            } else {
                $counter = LicenseUsageCounter::query()->create([
                    'license_id' => $lockedLicense->getKey(),
                    'vendor_account_id' => $vendorId,
                    'product_id' => $product->getKey(),
                    'metric' => $metric,
                    'period' => $period,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'quantity' => $nextQuantity,
                    'version' => 1,
                ]);
            }

            $eventPayload = [
                'counter_id' => (string) $counter->getKey(),
                'license_id' => (string) $lockedLicense->getKey(),
                'metric' => $metric,
                'period' => $period,
                'period_start' => $periodStart->toISOString(),
                'period_end' => $periodEnd?->toISOString(),
                'quantity_delta' => $quantity,
                'reason' => $reason,
                'resulting_quantity' => $nextQuantity,
            ];
            ksort($eventPayload, SORT_STRING);

            LicenseUsageEvent::query()->create([
                'counter_id' => $counter->getKey(),
                'license_id' => $lockedLicense->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $product->getKey(),
                'actor_user_id' => $actor->getKey(),
                'metric' => $metric,
                'period' => $period,
                'quantity_delta' => $quantity,
                'resulting_quantity' => $nextQuantity,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'reason' => $reason,
                'request_id' => $requestId,
                'payload_sha256' => $this->payloadHash($eventPayload),
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            return [
                'counter' => $counter->refresh(),
                'limit' => $limit,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'replayed' => false,
            ];
        }, 3);
    }

    private function assertUsable(License $license): void
    {
        if ($license->status !== 'active') {
            throw new LicenseUsageConflictException('Usage can only be recorded against an active licence.');
        }

        if ($license->starts_at !== null && $license->starts_at->isFuture()) {
            throw new LicenseUsageConflictException('Usage cannot be recorded before the licence start time.');
        }

        $effectiveExpiry = $license->grace_until ?? $license->expires_at;
        if ($effectiveExpiry !== null && $effectiveExpiry->lessThanOrEqualTo(now())) {
            throw new LicenseUsageConflictException('Usage cannot be recorded after the effective licence expiry.');
        }
    }

    /** @return array{Carbon, Carbon|null} */
    private function periodWindow(License $license, string $period): array
    {
        if ($period === 'lifetime') {
            $start = $license->starts_at ?? $license->created_at ?? Carbon::now('UTC');

            return [$start->copy()->utc(), null];
        }

        $start = Carbon::now('UTC');
        if ($period === 'monthly') {
            $start->startOfMonth()->startOfDay();
            $end = $start->copy()->addMonthNoOverflow();
        } else {
            $start->startOfYear()->startOfDay();
            $end = $start->copy()->addYearNoOverflow();
        }

        return [$start, $end];
    }

    private function authorizeActor(User $actor, VendorAccount $vendor, Product $product): void
    {
        if (
            $vendor->status !== 'active'
            || $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'licences.mutate')
        ) {
            throw new AuthorizationException('The actor may not record licence usage for this vendor.');
        }

        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->with('roles')
            ->first();

        if (! $membership instanceof VendorMembership) {
            throw new AuthorizationException('An active vendor membership is required to record licence usage.');
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
            throw new AuthorizationException('The actor must be assigned to this product to record its licence usage.');
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
}
