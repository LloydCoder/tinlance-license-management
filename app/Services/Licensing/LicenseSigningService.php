<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use App\Exceptions\LicenseKeyConfigurationException;
use App\Models\License;
use App\Models\PlanVersion;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tlms\Domain\Licensing\Ed25519Envelope;
use Tlms\Domain\Licensing\Ed25519KeyPair;
use Tlms\Domain\Licensing\LicenseLifecycle;
use Tlms\Domain\Licensing\SigningTrustMetadata;

final readonly class LicenseSigningService
{
    public function __construct(
        private LicenseSigningKeyMaterialProvider $keyMaterial,
    ) {}

    /**
     * @return array{signed: \stdClass, signatures: list<array{keyid: string, sig: string}>}
     */
    public function signLicense(
        License $license,
        PlanVersion $planVersion,
        SigningTrustMetadata $trust,
        DateTimeImmutable $now,
    ): array {
        if (! $trust->isCurrentAt($now)) {
            throw new LicenseKeyConfigurationException('Expired signing trust metadata cannot authorize licence signatures.');
        }

        $claims = $this->buildClaims($license, $planVersion);
        $keyPair = $this->keyMaterial->activeLicenseKeyPair($trust);

        try {
            $envelope = Ed25519Envelope::sign($claims, $keyPair);
            if (! $trust->verifyRoleEnvelope($envelope, 'license', $now)) {
                throw new LicenseKeyConfigurationException('The generated licence signature does not satisfy current trust policy.');
            }

            return $envelope;
        } finally {
            $keyPair->destroy();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildClaims(License $license, PlanVersion $planVersion): array
    {
        $licenseId = (string) $license->getKey();
        $planVersionId = (string) $planVersion->getKey();
        $snapshotHash = $planVersion->snapshot_sha256;
        $entitlements = $planVersion->entitlements;
        $issuedAt = $license->getAttribute('created_at');
        $publishedAt = $planVersion->published_at;

        if (
            $licenseId === ''
            || $planVersionId === ''
            || (string) $license->plan_version_id !== $planVersionId
            || (string) $license->vendor_account_id !== (string) $planVersion->vendor_account_id
            || (string) $license->product_id !== (string) $planVersion->product_id
            || $planVersion->status !== 'published'
            || ! is_string($snapshotHash)
            || preg_match('/\A[a-f0-9]{64}\z/D', $snapshotHash) !== 1
            || ! is_array($entitlements)
            || ! $issuedAt instanceof Carbon
            || ! $publishedAt instanceof Carbon
            || trim((string) $license->public_reference) === ''
            || trim((string) $license->customer_organization_id) === ''
            || ! in_array($license->status, [
                LicenseLifecycle::ISSUED,
                LicenseLifecycle::ACTIVE,
                LicenseLifecycle::SUSPENDED,
                LicenseLifecycle::REVOKED,
                LicenseLifecycle::EXPIRED,
            ], true)
            || (int) $license->version < 1
        ) {
            throw new InvalidArgumentException('The licence and published plan snapshot are not eligible for signing.');
        }

        return [
            'claim_type' => 'tlms.license',
            'schema_version' => 1,
            'license_id' => $licenseId,
            'public_reference' => (string) $license->public_reference,
            'vendor_account_id' => (string) $license->vendor_account_id,
            'customer_organization_id' => (string) $license->customer_organization_id,
            'product_id' => (string) $license->product_id,
            'plan_id' => (string) $planVersion->plan_id,
            'plan_version_id' => $planVersionId,
            'plan_version_number' => (int) $planVersion->version_number,
            'plan_version_published_at' => $publishedAt->toISOString(),
            'plan_snapshot_sha256' => $snapshotHash,
            'billing_model' => (string) $planVersion->billing_model,
            'currency' => (string) $planVersion->currency,
            'price_minor' => (int) $planVersion->price_minor,
            'billing_interval' => $planVersion->billing_interval,
            'trial_days' => (int) $planVersion->trial_days,
            'maintenance_days' => (int) $planVersion->maintenance_days,
            'entitlements' => $entitlements,
            'aggregate_version' => (int) $license->version,
            'status' => (string) $license->status,
            'issued_at' => $issuedAt->toISOString(),
            'starts_at' => $license->starts_at?->toISOString(),
            'expires_at' => $license->expires_at?->toISOString(),
            'grace_until' => $license->grace_until?->toISOString(),
        ];
    }
}
