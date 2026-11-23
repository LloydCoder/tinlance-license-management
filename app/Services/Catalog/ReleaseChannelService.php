<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\ReleaseChannelConflictException;
use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\ProductVersion;
use App\Models\ReleaseChannel;
use App\Models\ReleaseChannelEvent;
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
use InvalidArgumentException;

final readonly class ReleaseChannelService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private PlatformAccessAuditChainWriter $auditWriter,
        private PlatformAccessAuditChainHasher $auditHasher,
    ) {}

    /** @return LengthAwarePaginator<int, ReleaseChannel> */
    public function listFor(Product $product, int $perPage = 25): LengthAwarePaginator
    {
        return ReleaseChannel::query()
            ->where('product_id', $product->getKey())
            ->orderBy('slug')
            ->paginate(min(100, max(1, $perPage)));
    }

    public function create(
        User $actor,
        Product $product,
        string $slug,
        string $name,
        string $reason,
        string $requestId,
    ): ReleaseChannel {
        $slug = strtolower(trim($slug));
        $name = trim($name);
        $reason = trim($reason);

        if (
            preg_match('/\A[a-z][a-z0-9-]{1,31}\z/D', $slug) !== 1
            || mb_strlen($name) < 2
            || mb_strlen($name) > 100
            || mb_strlen($reason) < 5
            || mb_strlen($reason) > 500
        ) {
            throw new InvalidArgumentException('Release channel metadata is invalid.');
        }

        return DB::transaction(function () use ($actor, $product, $slug, $name, $reason, $requestId): ReleaseChannel {
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

            if (ReleaseChannel::query()->where('product_id', $lockedProduct->getKey())->where('slug', $slug)->exists()) {
                throw new InvalidArgumentException('This release channel already exists for the product.');
            }

            $channel = ReleaseChannel::query()->create([
                'product_id' => $lockedProduct->getKey(),
                'vendor_account_id' => $vendor->getKey(),
                'slug' => $slug,
                'name' => $name,
                'status' => 'active',
                'current_product_version_id' => null,
                'version_number' => 1,
                'created_by_user_id' => $actor->getKey(),
                'updated_by_user_id' => $actor->getKey(),
            ]);

            $this->appendEvent($channel, $lockedProduct, $actor, 'created', 1, null, null, $reason, $this->normalizeRequestId($requestId));

            return $channel->refresh();
        }, 3);
    }

    /**
     * @return array{0: ReleaseChannel, 1: bool}
     */
    public function promote(
        User $actor,
        ReleaseChannel $releaseChannel,
        string $productVersionId,
        int $expectedVersion,
        string $reason,
        string $requestId,
    ): array {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Release channel promotion reason is invalid.');
        }

        return DB::transaction(function () use ($actor, $releaseChannel, $productVersionId, $expectedVersion, $reason, $requestId): array {
            $channel = ReleaseChannel::query()->whereKey($releaseChannel->getKey())->lockForUpdate()->firstOrFail();
            $product = Product::query()->whereKey($channel->product_id)->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($channel->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount || $channel->status !== 'active') {
                throw new AuthorizationException('The release channel is not available for promotion.');
            }

            $this->authorizeProductManager($actor, $product, $vendor, 'releases.publish');

            if ((int) $channel->version_number !== $expectedVersion) {
                throw new ReleaseChannelConflictException('The release channel was changed by another request.');
            }

            $version = ProductVersion::query()
                ->whereKey($productVersionId)
                ->where('product_id', $product->getKey())
                ->where('status', 'published')
                ->first();

            if (! $version instanceof ProductVersion) {
                throw new InvalidArgumentException('Only a published version of this product may be promoted.');
            }

            if ((string) $channel->current_product_version_id === (string) $version->getKey()) {
                return [$channel, true];
            }

            $previousVersionId = $channel->current_product_version_id;
            $nextVersionNumber = $expectedVersion + 1;
            $this->appendEvent(
                $channel,
                $product,
                $actor,
                'promoted',
                $nextVersionNumber,
                $previousVersionId === null ? null : (string) $previousVersionId,
                (string) $version->getKey(),
                $reason,
                $this->normalizeRequestId($requestId),
            );

            $channel->forceFill([
                'current_product_version_id' => $version->getKey(),
                'version_number' => $nextVersionNumber,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            if ($product->status === 'draft') {
                $this->activateProduct($product, $actor, $reason, $requestId);
            }

            return [$channel->refresh(), false];
        }, 3);
    }

    private function activateProduct(Product $product, User $actor, string $reason, string $requestId): void
    {
        $before = ['status' => (string) $product->status];
        $product->forceFill([
            'status' => 'active',
            'version' => (int) $product->version + 1,
            'updated_by_user_id' => $actor->getKey(),
        ])->save();

        $after = ['status' => 'active'];
        $productRequestId = substr($requestId, 0, 118).'.activated';
        $changeDigest = $this->auditHasher->hashProfileChangeSet([
            'before' => $before,
            'after' => $after,
        ]);

        $event = ProductEvent::query()->create([
            'product_id' => $product->getKey(),
            'vendor_account_id' => $product->vendor_account_id,
            'actor_user_id' => $actor->getKey(),
            'event_type' => 'activated',
            'reason' => $reason,
            'changed_fields' => ['status'],
            'change_digest' => $changeDigest,
            'request_id' => $productRequestId,
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
            'product.activated',
            'product',
            (string) $product->getKey(),
            (string) $product->vendor_account_id,
            'allowed',
            'product_lifecycle_management',
            $productRequestId,
            (string) $event->getKey(),
            $detailsHash,
        );
    }

    private function authorizeProductManager(User $actor, Product $product, VendorAccount $vendor, string $permission): void
    {
        if ($this->platformAuthorization->isBootstrapSuperAdmin($actor)) {
            throw new AuthorizationException('The actor does not have the required release-channel permission.');
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
            throw new AuthorizationException('The actor does not have the required release-channel permission.');
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

    private function appendEvent(
        ReleaseChannel $channel,
        Product $product,
        User $actor,
        string $eventType,
        int $channelVersionNumber,
        ?string $previousVersionId,
        ?string $productVersionId,
        string $reason,
        string $requestId,
    ): void {
        $event = ReleaseChannelEvent::query()->create([
            'release_channel_id' => $channel->getKey(),
            'product_id' => $product->getKey(),
            'vendor_account_id' => $product->vendor_account_id,
            'actor_user_id' => $actor->getKey(),
            'event_type' => $eventType,
            'channel_version_number' => $channelVersionNumber,
            'previous_product_version_id' => $previousVersionId,
            'product_version_id' => $productVersionId,
            'reason' => $reason,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);

        $createdAt = (string) DB::table('release_channel_events')->where('id', $event->getKey())->value('created_at');
        $detailsHash = $this->auditHasher->hashReleaseChannelEvent(
            (string) $event->getKey(),
            (string) $channel->getKey(),
            (string) $product->getKey(),
            (string) $product->vendor_account_id,
            (string) $actor->getKey(),
            $eventType,
            $channelVersionNumber,
            $previousVersionId,
            $productVersionId,
            $reason,
            $requestId,
            $createdAt,
        );

        $this->auditWriter->append(
            (string) $actor->getKey(),
            'release_channel.'.$eventType,
            'release_channel',
            (string) $channel->getKey(),
            (string) $product->vendor_account_id,
            'allowed',
            'release_channel_management',
            $requestId,
            (string) $event->getKey(),
            $detailsHash,
        );
    }

    private function normalizeRequestId(string $requestId): string
    {
        return preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $requestId) === 1
            ? $requestId
            : bin2hex(random_bytes(16));
    }
}
