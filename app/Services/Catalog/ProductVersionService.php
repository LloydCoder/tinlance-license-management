<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\ProductVersionConflictException;
use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\ProductVersion;
use App\Models\ProductVersionEvent;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformAccessAuditChainHasher;
use App\Services\Authorization\PlatformAccessAuditChainWriter;
use App\Services\Authorization\PlatformSuperAdminAuthorization;
use App\Support\SemanticVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ProductVersionService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private PlatformAccessAuditChainWriter $auditWriter,
        private PlatformAccessAuditChainHasher $auditHasher,
    ) {}

    /** @return LengthAwarePaginator<int, ProductVersion> */
    public function listFor(Product $product, int $perPage = 25): LengthAwarePaginator
    {
        return ProductVersion::query()
            ->where('product_id', $product->getKey())
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(min(100, max(1, $perPage)));
    }

    public function create(
        User $actor,
        Product $product,
        string $version,
        ?string $releaseNotes,
        ?string $artifactSha256,
        ?int $artifactSizeBytes,
        string $reason,
        string $requestId,
    ): ProductVersion {
        $version = trim($version);
        $releaseNotes = $releaseNotes === null ? null : trim($releaseNotes);
        $artifactSha256 = $artifactSha256 === null ? null : strtolower(trim($artifactSha256));
        $reason = trim($reason);

        $this->validateMetadata($version, $releaseNotes, $artifactSha256, $artifactSizeBytes, $reason);

        return DB::transaction(function () use (
            $actor,
            $product,
            $version,
            $releaseNotes,
            $artifactSha256,
            $artifactSizeBytes,
            $reason,
            $requestId,
        ): ProductVersion {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($lockedProduct->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount) {
                throw new AuthorizationException('The product vendor is unavailable.');
            }

            $this->authorizeProductManager($actor, $lockedProduct, $vendor, 'plans.entitlements.manage');

            if (ProductVersion::query()->where('product_id', $lockedProduct->getKey())->where('version', $version)->exists()) {
                throw new InvalidArgumentException('This semantic version already exists for the product.');
            }

            $productVersion = ProductVersion::query()->create([
                'product_id' => $lockedProduct->getKey(),
                'vendor_account_id' => $vendor->getKey(),
                'version' => $version,
                'status' => 'draft',
                'release_notes' => $releaseNotes,
                'artifact_sha256' => $artifactSha256,
                'artifact_size_bytes' => $artifactSizeBytes,
                'version_number' => 1,
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
                'published_at' => null,
            ]);

            $after = [
                'version' => $version,
                'status' => 'draft',
                'release_notes' => $releaseNotes,
                'artifact_sha256' => $artifactSha256,
                'artifact_size_bytes' => $artifactSizeBytes,
                'published_at' => null,
            ];
            $this->appendEvent(
                $productVersion,
                $actor,
                'created',
                $reason,
                ['version', 'status', 'release_notes', 'artifact_sha256', 'artifact_size_bytes'],
                $this->auditHasher->hashProfileChangeSet(['before' => [], 'after' => $after]),
                $this->normalizeRequestId($requestId),
            );

            return $productVersion->refresh();
        }, 3);
    }

    /**
     * @param  array{release_notes: string|null, artifact_sha256: string|null, artifact_size_bytes: int|null}  $changes
     */
    public function update(
        User $actor,
        ProductVersion $productVersion,
        int $expectedVersion,
        array $changes,
        string $reason,
        string $requestId,
    ): ProductVersion {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Product version update reason is invalid.');
        }

        return DB::transaction(function () use ($actor, $productVersion, $expectedVersion, $changes, $reason, $requestId): ProductVersion {
            $current = ProductVersion::query()->whereKey($productVersion->getKey())->lockForUpdate()->firstOrFail();
            $product = Product::query()->whereKey($current->product_id)->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($current->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount || $current->status !== 'draft') {
                throw new AuthorizationException('The product version is not available for editing.');
            }

            $this->authorizeProductManager($actor, $product, $vendor, 'plans.entitlements.manage');

            if ((int) $current->version_number !== $expectedVersion) {
                throw new ProductVersionConflictException('The product version was changed by another request.');
            }

            $normalized = [
                'release_notes' => $changes['release_notes'] === null ? null : trim($changes['release_notes']),
                'artifact_sha256' => $changes['artifact_sha256'] === null ? null : strtolower(trim($changes['artifact_sha256'])),
                'artifact_size_bytes' => $changes['artifact_size_bytes'],
            ];
            $this->validateMetadata((string) $current->version, $normalized['release_notes'], $normalized['artifact_sha256'], $normalized['artifact_size_bytes'], $reason);

            $before = [];
            $after = [];
            $changedFields = [];
            foreach ($normalized as $field => $value) {
                $before[$field] = $current->getAttribute($field);
                $after[$field] = $value;
                if ($before[$field] !== $value) {
                    $changedFields[] = $field;
                }
            }

            if ($changedFields === []) {
                return $current;
            }

            $current->forceFill([
                ...$normalized,
                'version_number' => $expectedVersion + 1,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->appendEvent(
                $current,
                $actor,
                'updated',
                $reason,
                $changedFields,
                $this->auditHasher->hashProfileChangeSet(['before' => $before, 'after' => $after]),
                $this->normalizeRequestId($requestId),
            );

            return $current->refresh();
        }, 3);
    }

    public function publish(
        User $actor,
        ProductVersion $productVersion,
        string $reason,
        string $requestId,
    ): ProductVersion {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Product version publish reason is invalid.');
        }

        return DB::transaction(function () use ($actor, $productVersion, $reason, $requestId): ProductVersion {
            $current = ProductVersion::query()->whereKey($productVersion->getKey())->lockForUpdate()->firstOrFail();
            $product = Product::query()->whereKey($current->product_id)->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($current->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount || $current->status !== 'draft') {
                throw new AuthorizationException('The product version is not available for publishing.');
            }

            $this->authorizeProductManager($actor, $product, $vendor, 'releases.publish');

            if (
                ! is_string($current->artifact_sha256)
                || preg_match('/\A[a-f0-9]{64}\z/D', $current->artifact_sha256) !== 1
                || ! is_int($current->artifact_size_bytes)
                || $current->artifact_size_bytes < 1
            ) {
                throw new InvalidArgumentException('A valid SHA-256 digest and positive artifact size are required before publishing.');
            }

            $before = ['status' => 'draft', 'published_at' => null];
            $publishedAt = now();
            $current->forceFill([
                'status' => 'published',
                'published_at' => $publishedAt,
                'version_number' => (int) $current->version_number + 1,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->appendEvent(
                $current,
                $actor,
                'published',
                $reason,
                ['status', 'published_at'],
                $this->auditHasher->hashProfileChangeSet([
                    'before' => $before,
                    'after' => ['status' => 'published', 'published_at' => $publishedAt->toISOString()],
                ]),
                $this->normalizeRequestId($requestId),
            );

            return $current->refresh();
        }, 3);
    }

    private function authorizeProductManager(User $actor, Product $product, VendorAccount $vendor, string $permission): void
    {
        if ($this->platformAuthorization->isBootstrapSuperAdmin($actor)) {
            throw new AuthorizationException('The actor does not have the required product-version permission.');
        }

        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (
            ! $membership instanceof VendorMembership
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, $permission)
        ) {
            throw new AuthorizationException('The actor does not have the required product-version permission.');
        }

        if ($this->isVendorSuperAdmin($actor, $vendor)) {
            return;
        }

        if (! ProductAssignment::query()
            ->where('product_id', $product->getKey())
            ->where('vendor_membership_id', $membership->getKey())
            ->whereNull('revoked_at')
            ->exists()) {
            throw new AuthorizationException('The actor is not assigned to this product.');
        }
    }

    private function isVendorSuperAdmin(User $actor, VendorAccount $vendor): bool
    {
        return $actor->vendorMemberships()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('status', 'active')
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();
    }

    /**
     * @param  list<string>  $changedFields
     */
    private function appendEvent(
        ProductVersion $productVersion,
        User $actor,
        string $eventType,
        string $reason,
        array $changedFields,
        string $changeDigest,
        string $requestId,
    ): void {
        $event = ProductVersionEvent::query()->create([
            'product_version_id' => $productVersion->getKey(),
            'product_id' => $productVersion->product_id,
            'vendor_account_id' => $productVersion->vendor_account_id,
            'actor_user_id' => $actor->getKey(),
            'event_type' => $eventType,
            'reason' => $reason,
            'changed_fields' => $changedFields,
            'change_digest' => $changeDigest,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);

        $createdAt = (string) DB::table('product_version_events')->where('id', $event->getKey())->value('created_at');
        $detailsHash = $this->auditHasher->hashProductVersionEvent(
            (string) $event->getKey(),
            (string) $productVersion->getKey(),
            (string) $productVersion->product_id,
            (string) $productVersion->vendor_account_id,
            (string) $actor->getKey(),
            $eventType,
            $reason,
            $changedFields,
            $changeDigest,
            $requestId,
            $createdAt,
        );

        $this->auditWriter->append(
            (string) $actor->getKey(),
            'product_version.'.$eventType,
            'product_version',
            (string) $productVersion->getKey(),
            (string) $productVersion->vendor_account_id,
            'allowed',
            'product_version_management',
            $requestId,
            (string) $event->getKey(),
            $detailsHash,
        );
    }

    private function validateMetadata(
        string $version,
        ?string $releaseNotes,
        ?string $artifactSha256,
        ?int $artifactSizeBytes,
        string $reason,
    ): void {
        if (
            ! SemanticVersion::isValid($version)
            || ($releaseNotes !== null && mb_strlen($releaseNotes) > 5000)
            || ($artifactSha256 !== null && preg_match('/\A[a-f0-9]{64}\z/D', $artifactSha256) !== 1)
            || ($artifactSizeBytes !== null && $artifactSizeBytes < 1)
            || mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
        ) {
            throw new InvalidArgumentException('Product version metadata is invalid.');
        }
    }

    private function normalizeRequestId(string $requestId): string
    {
        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : bin2hex(random_bytes(16));
    }
}
