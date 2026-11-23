<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\ProductVersionConflictException;
use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\ProductEvent;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformAccessAuditChainHasher;
use App\Services\Authorization\PlatformAccessAuditChainWriter;
use App\Services\Authorization\PlatformSuperAdminAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ProductCatalogService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private PlatformAccessAuditChainWriter $auditWriter,
        private PlatformAccessAuditChainHasher $auditHasher,
        private ProductAssignmentService $productAssignments,
    ) {}

    public function vendorForRead(User $actor): ?VendorAccount
    {
        $vendor = VendorAccount::query()
            ->where('installation_key', 'primary')
            ->where('status', 'active')
            ->first();

        if (
            ! $vendor instanceof VendorAccount
            || (! $this->membershipAuthorization->vendorCan($actor, $vendor, 'products.read')
                && ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'products.manage'))
        ) {
            return null;
        }

        return $vendor;
    }

    public function vendorForWrite(User $actor): ?VendorAccount
    {
        $vendor = $this->vendorForRead($actor);

        if ($vendor === null || $this->platformAuthorization->isBootstrapSuperAdmin($actor)) {
            return null;
        }

        return $vendor;
    }

    /** @return LengthAwarePaginator<int, Product> */
    public function listFor(User $actor, VendorAccount $vendor, int $perPage = 25): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->orderByDesc('created_at')
            ->orderBy('id');

        if (! $this->isVendorSuperAdmin($actor, $vendor)) {
            $membership = VendorMembership::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->first();

            if (! $membership instanceof VendorMembership) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('assignments', static fn ($assignments) => $assignments
                    ->where('vendor_membership_id', $membership->getKey())
                    ->whereNull('revoked_at'));
            }
        }

        return $query->paginate(min(100, max(1, $perPage)));
    }

    public function create(
        User $actor,
        VendorAccount $vendor,
        string $name,
        string $slug,
        ?string $description,
        string $reason,
        string $requestId,
    ): Product {
        $name = trim($name);
        $slug = Str::lower(trim($slug));
        $description = $description === null ? null : trim($description);
        $reason = trim($reason);

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 160
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1
            || mb_strlen($slug) > 80
            || ($description !== null && mb_strlen($description) > 2000)
            || mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
        ) {
            throw new InvalidArgumentException('Product catalogue metadata is invalid.');
        }

        return DB::transaction(function () use (
            $actor,
            $vendor,
            $name,
            $slug,
            $description,
            $reason,
            $requestId,
        ): Product {
            $lockedVendor = VendorAccount::query()
                ->whereKey($vendor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $lockedVendor instanceof VendorAccount) {
                throw new AuthorizationException('The vendor account is unavailable.');
            }

            $this->authorizeVendorManager($actor, $lockedVendor);

            if (Product::query()->where('vendor_account_id', $lockedVendor->getKey())->where('slug', $slug)->exists()) {
                throw new InvalidArgumentException('A product with this slug already exists for the vendor.');
            }

            $product = Product::query()->create([
                'public_id' => (string) Str::uuid(),
                'vendor_account_id' => $lockedVendor->getKey(),
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'status' => 'draft',
                'version' => 1,
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ]);

            $after = [
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'created_by_user_id' => (string) $actor->getKey(),
            ];
            $changeDigest = $this->auditHasher->hashProfileChangeSet([
                'before' => [],
                'after' => $after,
            ]);

            $normalizedRequestId = $this->normalizeRequestId($requestId);
            $this->appendProductEvent(
                $product,
                $actor,
                'created',
                $reason,
                ['name', 'slug', 'description'],
                $changeDigest,
                $normalizedRequestId,
            );
            $this->productAssignments->grantCreator($actor, $product, $lockedVendor, $normalizedRequestId);

            return $product->refresh();
        }, 3);
    }

    /**
     * @param  array{name: string, slug: string, description: string|null}  $changes
     */
    public function update(
        User $actor,
        Product $product,
        int $expectedVersion,
        array $changes,
        string $reason,
        string $requestId,
    ): Product {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Product update reason is invalid.');
        }

        return DB::transaction(function () use (
            $actor,
            $product,
            $expectedVersion,
            $changes,
            $reason,
            $requestId,
        ): Product {
            $current = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($current->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount || $current->status !== 'draft') {
                throw new AuthorizationException('The product is not available for editing.');
            }

            $this->authorizeProductManager($actor, $current, $vendor);

            if ((int) $current->version !== $expectedVersion) {
                throw new ProductVersionConflictException('The product was changed by another request.');
            }

            $normalized = [
                'name' => trim($changes['name']),
                'slug' => Str::lower(trim($changes['slug'])),
                'description' => $changes['description'] === null ? null : trim($changes['description']),
            ];

            if (
                mb_strlen($normalized['name']) < 2
                || mb_strlen($normalized['name']) > 160
                || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $normalized['slug']) !== 1
                || mb_strlen($normalized['slug']) > 80
                || ($normalized['description'] !== null && mb_strlen($normalized['description']) > 2000)
            ) {
                throw new InvalidArgumentException('Product catalogue metadata is invalid.');
            }

            if (
                Product::query()
                    ->where('vendor_account_id', $vendor->getKey())
                    ->where('slug', $normalized['slug'])
                    ->where('id', '<>', $current->getKey())
                    ->exists()
            ) {
                throw new InvalidArgumentException('A product with this slug already exists for the vendor.');
            }

            $before = [];
            $after = [];
            $changedFields = [];

            foreach ($normalized as $field => $value) {
                if ($current->getAttribute($field) !== $value) {
                    $before[$field] = $current->getAttribute($field);
                    $after[$field] = $value;
                    $changedFields[] = $field;
                }
            }

            if ($changedFields === []) {
                return $current;
            }

            $current->forceFill([
                ...$normalized,
                'version' => $expectedVersion + 1,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $changeDigest = $this->auditHasher->hashProfileChangeSet([
                'before' => $before,
                'after' => $after,
            ]);

            $this->appendProductEvent(
                $current,
                $actor,
                'updated',
                $reason,
                $changedFields,
                $changeDigest,
                $this->normalizeRequestId($requestId),
            );

            return $current->refresh();
        }, 3);
    }

    /** @param list<string> $changedFields */
    private function appendProductEvent(
        Product $product,
        User $actor,
        string $eventType,
        string $reason,
        array $changedFields,
        string $changeDigest,
        string $requestId,
    ): void {
        $event = ProductEvent::query()->create([
            'product_id' => $product->getKey(),
            'vendor_account_id' => $product->vendor_account_id,
            'actor_user_id' => $actor->getKey(),
            'event_type' => $eventType,
            'reason' => $reason,
            'changed_fields' => $changedFields,
            'change_digest' => $changeDigest,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);

        $createdAt = (string) DB::table('product_events')->where('id', $event->getKey())->value('created_at');
        $detailsHash = $this->auditHasher->hashProductEvent(
            (string) $event->getKey(),
            (string) $event->product_id,
            (string) $event->vendor_account_id,
            (string) $event->actor_user_id,
            (string) $event->event_type,
            (string) $event->reason,
            $event->changed_fields,
            (string) $event->change_digest,
            (string) $event->request_id,
            $createdAt,
        );

        $this->auditWriter->append(
            (string) $actor->getKey(),
            'product.'.$eventType,
            'product',
            (string) $product->getKey(),
            (string) $product->vendor_account_id,
            'allowed',
            'product_catalog_management',
            $requestId,
            (string) $event->getKey(),
            $detailsHash,
        );
    }

    private function authorizeVendorManager(User $actor, VendorAccount $vendor): void
    {
        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (
            ! $membership instanceof VendorMembership
            || $this->platformAuthorization->isBootstrapSuperAdmin($actor)
            || ! $this->membershipAuthorization->vendorCan($actor, $vendor, 'products.manage')
        ) {
            throw new AuthorizationException('The actor cannot manage products for this vendor.');
        }
    }

    private function authorizeProductManager(User $actor, Product $product, VendorAccount $vendor): void
    {
        $this->authorizeVendorManager($actor, $vendor);

        if (! $this->isVendorSuperAdmin($actor, $vendor) && ! $this->isAssigned($actor, $product, $vendor)) {
            throw new AuthorizationException('The actor is not assigned to this product.');
        }
    }

    private function isAssigned(User $actor, Product $product, VendorAccount $vendor): bool
    {
        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->first();

        return $membership instanceof VendorMembership
            && ProductAssignment::query()
                ->where('product_id', $product->getKey())
                ->where('vendor_membership_id', $membership->getKey())
                ->whereNull('revoked_at')
                ->exists();
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

    private function normalizeRequestId(string $requestId): string
    {
        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : bin2hex(random_bytes(16));
    }
}
