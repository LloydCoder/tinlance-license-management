<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Exceptions\LicenseIssuanceConflictException;
use App\Exceptions\LicenseSecretUnavailableException;
use App\Models\CustomerOrganization;
use App\Models\License;
use App\Models\LicenseEvent;
use App\Models\LicenseIssuanceRequest;
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
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tlms\Domain\Licensing\LicenseIssuanceFingerprint;
use Tlms\Domain\Licensing\LicenseKey;

final readonly class LicenseIssuanceService
{
    private const OPERATION = 'license.issue.v1';

    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private LicenseKeyMaterialProvider $keyMaterial,
    ) {}

    /**
     * @return array{license: License, issuance_request: LicenseIssuanceRequest, replayed: bool, secret_available: bool}
     */
    public function issue(
        User $actor,
        Product $product,
        CustomerOrganization $customer,
        PlanVersion $planVersion,
        ?string $startsAt,
        string $reason,
        string $idempotencyKey,
        string $requestId,
    ): array {
        $reason = trim($reason);
        $idempotencyKey = trim($idempotencyKey);
        $requestId = trim($requestId);

        if (
            mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
            || preg_match('/\A[A-Za-z0-9._~-]{32,128}\z/D', $idempotencyKey) !== 1
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) !== 1
        ) {
            throw new InvalidArgumentException('Licence issuance request metadata is invalid.');
        }

        $normalizedStart = ($startsAt === null ? now() : Carbon::parse($startsAt))->utc();
        $startValue = $normalizedStart->format('Y-m-d\TH:i:s.u\Z');
        $fingerprintPayload = [
            'product_id' => (string) $product->getKey(),
            'customer_organization_id' => (string) $customer->getKey(),
            'plan_version_id' => (string) $planVersion->getKey(),
            'starts_at' => $startValue,
            'reason' => $reason,
        ];

        $vendorId = (string) $product->vendor_account_id;
        $actorId = (string) $actor->getKey();
        $fingerprint = LicenseIssuanceFingerprint::create($vendorId, $actorId, $fingerprintPayload);
        $idempotencyHash = hash('sha256', $idempotencyKey);

        return DB::transaction(function () use (
            $actor,
            $product,
            $customer,
            $planVersion,
            $normalizedStart,
            $reason,
            $requestId,
            $vendorId,
            $actorId,
            $fingerprint,
            $idempotencyHash,
        ): array {
            $vendor = VendorAccount::query()->whereKey($vendorId)->lockForUpdate()->firstOrFail();
            if ($vendor->status !== 'active') {
                throw new AuthorizationException('The licence vendor is unavailable.');
            }

            /** @var Product $lockedProduct */
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            /** @var CustomerOrganization $lockedCustomer */
            $lockedCustomer = CustomerOrganization::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            /** @var PlanVersion $lockedPlanVersion */
            $lockedPlanVersion = PlanVersion::query()->whereKey($planVersion->getKey())->lockForUpdate()->firstOrFail();

            $this->authorizeIssuer($actor, $vendor, $lockedProduct);

            if (
                (string) $lockedProduct->vendor_account_id !== $vendorId
                || $lockedProduct->status !== 'active'
                || (string) $lockedCustomer->vendor_account_id !== $vendorId
                || $lockedCustomer->status !== 'active'
                || (string) $lockedPlanVersion->vendor_account_id !== $vendorId
                || (string) $lockedPlanVersion->product_id !== (string) $lockedProduct->getKey()
                || $lockedPlanVersion->status !== 'published'
            ) {
                throw new InvalidArgumentException('The customer, product and published plan version must share an active vendor scope.');
            }

            $existing = LicenseIssuanceRequest::query()
                ->where('vendor_account_id', $vendorId)
                ->where('operation', self::OPERATION)
                ->where('idempotency_key_sha256', $idempotencyHash)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof LicenseIssuanceRequest) {
                if (
                    (string) $existing->actor_user_id !== $actorId
                    || ! hash_equals((string) $existing->request_fingerprint_sha256, $fingerprint)
                ) {
                    throw new LicenseIssuanceConflictException('The idempotency key was already used for a different issuance request.');
                }

                if ($existing->license_id === null) {
                    throw new LicenseIssuanceConflictException('The previous issuance request is still processing.');
                }

                $existingLicense = License::query()
                    ->whereKey($existing->license_id)
                    ->where('vendor_account_id', $vendorId)
                    ->firstOrFail();

                return [
                    'license' => $existingLicense,
                    'issuance_request' => $existing,
                    'replayed' => true,
                    'secret_available' => $existing->status === 'completed'
                        && $existing->response_ciphertext !== null
                        && $existing->response_delivered_at === null
                        && $existing->expires_at !== null
                        && $existing->expires_at->isFuture(),
                ];
            }

            if (LicenseEvent::query()->where('vendor_account_id', $vendorId)->where('request_id', $requestId)->exists()) {
                throw new LicenseIssuanceConflictException('The request identifier has already been used for a licence event.');
            }

            $issuedKey = LicenseKey::issue(
                $this->keyMaterial->activePepperMaterial(),
                $this->keyMaterial->activePepperKeyId(),
            );

            $licenseExpiry = $this->calculateExpiry($lockedPlanVersion, $normalizedStart);
            $license = License::query()->create([
                'vendor_account_id' => $vendorId,
                'customer_organization_id' => $lockedCustomer->getKey(),
                'product_id' => $lockedProduct->getKey(),
                'plan_version_id' => $lockedPlanVersion->getKey(),
                'public_reference' => 'LIC-'.Str::ulid(),
                'key_verifier' => $issuedKey->verifier,
                'pepper_key_id' => $issuedKey->pepperKeyId,
                'status' => 'issued',
                'starts_at' => $normalizedStart,
                'expires_at' => $licenseExpiry,
                'grace_until' => null,
                'version' => 1,
                'created_by_user_id' => $actorId,
            ]);

            $issuanceRequest = LicenseIssuanceRequest::query()->create([
                'vendor_account_id' => $vendorId,
                'actor_user_id' => $actorId,
                'operation' => self::OPERATION,
                'idempotency_key_sha256' => $idempotencyHash,
                'request_fingerprint_sha256' => $fingerprint,
                'status' => 'processing',
                'license_id' => null,
                'response_ciphertext' => null,
                'expires_at' => now()->addSeconds($this->secretTtlSeconds()),
            ]);

            $eventPayload = [
                'license_id' => (string) $license->getKey(),
                'public_reference' => (string) $license->public_reference,
                'customer_organization_id' => (string) $lockedCustomer->getKey(),
                'product_id' => (string) $lockedProduct->getKey(),
                'plan_version_id' => (string) $lockedPlanVersion->getKey(),
                'plan_version_number' => (int) $lockedPlanVersion->version_number,
                'status' => 'issued',
                'starts_at' => $normalizedStart->toISOString(),
                'expires_at' => $licenseExpiry?->toISOString(),
            ];
            ksort($eventPayload, SORT_STRING);
            $eventPayloadHash = hash('sha256', json_encode(
                $eventPayload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            LicenseEvent::query()->create([
                'license_id' => $license->getKey(),
                'vendor_account_id' => $vendorId,
                'product_id' => $lockedProduct->getKey(),
                'actor_type' => 'user',
                'actor_user_id' => $actorId,
                'event_type' => 'issued',
                'from_status' => null,
                'to_status' => 'issued',
                'reason' => $reason,
                'request_id' => $requestId,
                'payload_sha256' => $eventPayloadHash,
                'event_payload' => $eventPayload,
                'created_at' => now(),
            ]);

            $context = $this->responseContext($vendorId, (string) $issuanceRequest->getKey(), (string) $license->getKey());
            $ciphertext = $this->keyMaterial->responseCipherRing()->encrypt($issuedKey->plaintext, $context);
            $issuanceRequest->fill([
                'status' => 'completed',
                'license_id' => $license->getKey(),
                'response_ciphertext' => $ciphertext,
            ])->save();

            return [
                'license' => $license->refresh(),
                'issuance_request' => $issuanceRequest->refresh(),
                'replayed' => false,
                'secret_available' => true,
            ];
        }, 3);
    }

    public function revealSecret(User $actor, LicenseIssuanceRequest $issuanceRequest, string $requestId): string
    {
        $requestId = trim($requestId);
        if (preg_match('/\\A[A-Za-z0-9._:-]{1,128}\\z/D', $requestId) !== 1) {
            throw new InvalidArgumentException('The secret-delivery request identifier is invalid.');
        }

        return DB::transaction(function () use ($actor, $issuanceRequest, $requestId): string {
            /** @var LicenseIssuanceRequest $lockedRequest */
            $lockedRequest = LicenseIssuanceRequest::query()->whereKey($issuanceRequest->getKey())->lockForUpdate()->firstOrFail();
            if ((string) $lockedRequest->actor_user_id !== (string) $actor->getKey()) {
                throw new AuthorizationException('Only the issuing actor may retrieve this one-time licence secret.');
            }

            $vendor = VendorAccount::query()->whereKey($lockedRequest->vendor_account_id)->lockForUpdate()->firstOrFail();
            /** @var License $license */
            $license = License::query()->whereKey($lockedRequest->license_id)->lockForUpdate()->firstOrFail();
            /** @var Product $product */
            $product = Product::query()->whereKey($license->product_id)->firstOrFail();
            $this->authorizeIssuer($actor, $vendor, $product);

            if (
                $lockedRequest->status !== 'completed'
                || $lockedRequest->response_ciphertext === null
                || $lockedRequest->response_delivered_at !== null
                || $lockedRequest->expires_at === null
                || $lockedRequest->expires_at->isPast()
            ) {
                throw new LicenseSecretUnavailableException('The one-time licence secret is no longer available.');
            }

            if (LicenseEvent::query()
                ->where('vendor_account_id', $lockedRequest->vendor_account_id)
                ->where('request_id', $requestId)
                ->exists()) {
                throw new LicenseIssuanceConflictException('The request identifier has already been used for a licence event.');
            }

            $context = $this->responseContext(
                (string) $lockedRequest->vendor_account_id,
                (string) $lockedRequest->getKey(),
                (string) $license->getKey(),
            );
            $plaintext = $this->keyMaterial->responseCipherRing()->decrypt(
                (string) $lockedRequest->response_ciphertext,
                $context,
            );

            $verification = $this->keyMaterial->pepperRing()->verifyAndUpgrade(
                $plaintext,
                (string) $license->key_verifier,
                (string) $license->pepper_key_id,
            );
            if (! $verification->valid) {
                throw new RuntimeException('The encrypted licence secret does not match its stored verifier.');
            }

            $deliveredAt = now();
            $lockedRequest->fill([
                'status' => 'delivered',
                'response_ciphertext' => null,
                'response_delivered_at' => $deliveredAt,
            ])->save();

            $eventPayload = [
                'issuance_request_id' => (string) $lockedRequest->getKey(),
                'delivery' => 'one_time_api',
                'delivered_at' => $deliveredAt->toISOString(),
            ];
            ksort($eventPayload, SORT_STRING);
            LicenseEvent::query()->create([
                'license_id' => $license->getKey(),
                'vendor_account_id' => $vendor->getKey(),
                'product_id' => $product->getKey(),
                'actor_type' => 'user',
                'actor_user_id' => $actor->getKey(),
                'event_type' => 'secret_delivered',
                'from_status' => (string) $license->status,
                'to_status' => (string) $license->status,
                'reason' => 'One-time issuance secret delivered to the issuing actor.',
                'request_id' => $requestId,
                'payload_sha256' => hash('sha256', json_encode(
                    $eventPayload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                )),
                'event_payload' => $eventPayload,
                'created_at' => $deliveredAt,
            ]);

            return $plaintext;
        }, 3);
    }

    private function authorizeIssuer(User $actor, VendorAccount $vendor, Product $product): void
    {
        if (
            $vendor->status !== 'active'
            || $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'licences.issue')
        ) {
            throw new AuthorizationException('The actor may not issue licences for this vendor.');
        }

        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->with('roles')
            ->first();

        if (! $membership instanceof VendorMembership) {
            throw new AuthorizationException('An active vendor membership is required to issue licences.');
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
            throw new AuthorizationException('The actor must be assigned to this product to issue or reveal its licence secret.');
        }
    }

    private function calculateExpiry(PlanVersion $planVersion, Carbon $startsAt): ?Carbon
    {
        return match ($planVersion->billing_model) {
            'perpetual' => null,
            'annual' => $startsAt->copy()->addYearNoOverflow(),
            'subscription' => $planVersion->billing_interval === 'month'
                ? $startsAt->copy()->addMonthNoOverflow()
                : $startsAt->copy()->addYearNoOverflow(),
            'trial' => $startsAt->copy()->addDays((int) $planVersion->trial_days),
            default => throw new InvalidArgumentException('The published plan billing model is unsupported.'),
        };
    }

    private function secretTtlSeconds(): int
    {
        return max(60, min(86400, (int) config('tlms.licensing.issuance_secret_ttl_seconds', 900)));
    }

    private function responseContext(string $vendorId, string $requestId, string $licenseId): string
    {
        return $vendorId.':'.$requestId.':'.$licenseId;
    }
}
