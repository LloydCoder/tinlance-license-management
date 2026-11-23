<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\ProductAssignmentEvent;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformAccessAuditChainHasher;
use App\Services\Authorization\PlatformAccessAuditChainWriter;
use App\Services\Authorization\PlatformSuperAdminAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ProductAssignmentService
{
    public function __construct(
        private MembershipAuthorization $membershipAuthorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
        private PlatformAccessAuditChainWriter $auditWriter,
        private PlatformAccessAuditChainHasher $auditHasher,
    ) {}

    public function grantCreator(User $creator, Product $product, VendorAccount $vendor, string $requestId): void
    {
        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $creator->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (
            ! $membership instanceof VendorMembership
            || ! $this->membershipAuthorization->vendorCan($creator, $vendor, 'products.manage')
        ) {
            throw new AuthorizationException('The product creator must have an active product-management membership.');
        }

        $assignment = ProductAssignment::query()
            ->where('product_id', $product->getKey())
            ->where('vendor_membership_id', $membership->getKey())
            ->lockForUpdate()
            ->first();

        if ($assignment instanceof ProductAssignment && $assignment->revoked_at === null) {
            return;
        }

        if ($assignment instanceof ProductAssignment) {
            $assignment->forceFill([
                'assigned_by_user_id' => $creator->getKey(),
                'assigned_at' => now(),
                'revoked_at' => null,
            ])->save();
        } else {
            $assignment = ProductAssignment::query()->create([
                'product_id' => $product->getKey(),
                'vendor_account_id' => $vendor->getKey(),
                'vendor_membership_id' => $membership->getKey(),
                'assigned_by_user_id' => $creator->getKey(),
                'assigned_at' => now(),
                'revoked_at' => null,
            ]);
        }

        $this->appendEvent($assignment, $product, $creator, $membership, 'granted', 'Initial product creator assignment.', $requestId);
    }

    /**
     * @return array{0: ProductAssignment, 1: bool}
     */
    public function grant(
        User $actor,
        Product $product,
        string $targetMembershipId,
        string $reason,
        string $requestId,
    ): array {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Product assignment reason is invalid.');
        }

        return DB::transaction(function () use ($actor, $product, $targetMembershipId, $reason, $requestId): array {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($lockedProduct->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount) {
                throw new AuthorizationException('The product vendor is unavailable.');
            }

            $this->authorizeVendorSuperAdmin($actor, $vendor);

            $targetMembership = VendorMembership::query()
                ->whereKey($targetMembershipId)
                ->where('vendor_account_id', $vendor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $targetMembership instanceof VendorMembership) {
                throw new InvalidArgumentException('The target must be an active member of this vendor account.');
            }

            $targetUser = User::query()
                ->whereKey($targetMembership->user_id)
                ->where('status', 'active')
                ->first();

            if (
                ! $targetUser instanceof User
                || $this->platformAuthorization->isBootstrapSuperAdmin($targetUser)
                || (
                    ! $this->membershipAuthorization->vendorCan($targetUser, $vendor, 'products.read')
                    && ! $this->membershipAuthorization->vendorCan($targetUser, $vendor, 'products.manage')
                )
            ) {
                throw new InvalidArgumentException('The target member does not have a product-scoped role.');
            }

            $assignment = ProductAssignment::query()
                ->where('product_id', $lockedProduct->getKey())
                ->where('vendor_membership_id', $targetMembership->getKey())
                ->lockForUpdate()
                ->first();

            if ($assignment instanceof ProductAssignment && $assignment->revoked_at === null) {
                return [$assignment, true];
            }

            if ($assignment instanceof ProductAssignment) {
                $assignment->forceFill([
                    'assigned_by_user_id' => $actor->getKey(),
                    'assigned_at' => now(),
                    'revoked_at' => null,
                ])->save();
            } else {
                $assignment = ProductAssignment::query()->create([
                    'product_id' => $lockedProduct->getKey(),
                    'vendor_account_id' => $vendor->getKey(),
                    'vendor_membership_id' => $targetMembership->getKey(),
                    'assigned_by_user_id' => $actor->getKey(),
                    'assigned_at' => now(),
                    'revoked_at' => null,
                ]);
            }

            $this->appendEvent($assignment, $lockedProduct, $actor, $targetMembership, 'granted', $reason, $this->normalizeRequestId($requestId));

            return [$assignment, false];
        }, 3);
    }

    public function revoke(
        User $actor,
        Product $product,
        string $targetMembershipId,
        string $reason,
        string $requestId,
    ): void {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Product assignment reason is invalid.');
        }

        DB::transaction(function () use ($actor, $product, $targetMembershipId, $reason, $requestId): void {
            $lockedProduct = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $vendor = VendorAccount::query()
                ->whereKey($lockedProduct->vendor_account_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $vendor instanceof VendorAccount) {
                throw new AuthorizationException('The product vendor is unavailable.');
            }

            $this->authorizeVendorSuperAdmin($actor, $vendor);

            $targetMembership = VendorMembership::query()
                ->whereKey($targetMembershipId)
                ->where('vendor_account_id', $vendor->getKey())
                ->lockForUpdate()
                ->first();

            if (! $targetMembership instanceof VendorMembership) {
                throw new InvalidArgumentException('The target membership does not belong to this vendor account.');
            }

            $assignment = ProductAssignment::query()
                ->where('product_id', $lockedProduct->getKey())
                ->where('vendor_membership_id', $targetMembership->getKey())
                ->lockForUpdate()
                ->first();

            if (! $assignment instanceof ProductAssignment || $assignment->revoked_at !== null) {
                return;
            }

            $assignment->forceFill(['revoked_at' => now()])->save();
            $this->appendEvent($assignment, $lockedProduct, $actor, $targetMembership, 'revoked', $reason, $this->normalizeRequestId($requestId));
        }, 3);
    }

    private function authorizeVendorSuperAdmin(User $actor, VendorAccount $vendor): void
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
            || ! $this->isVendorSuperAdmin($actor, $vendor)
        ) {
            throw new AuthorizationException('Only a vendor Super Admin may change product assignments.');
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
        ProductAssignment $assignment,
        Product $product,
        User $actor,
        VendorMembership $targetMembership,
        string $eventType,
        string $reason,
        string $requestId,
    ): void {
        $event = ProductAssignmentEvent::query()->create([
            'assignment_id' => $assignment->getKey(),
            'product_id' => $product->getKey(),
            'vendor_account_id' => $product->vendor_account_id,
            'actor_user_id' => $actor->getKey(),
            'target_vendor_membership_id' => $targetMembership->getKey(),
            'event_type' => $eventType,
            'reason' => $reason,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);

        $createdAt = (string) DB::table('product_assignment_events')->where('id', $event->getKey())->value('created_at');
        $detailsHash = $this->auditHasher->hashProductAssignmentEvent(
            (string) $event->getKey(),
            (string) $assignment->getKey(),
            (string) $product->getKey(),
            (string) $product->vendor_account_id,
            (string) $actor->getKey(),
            (string) $targetMembership->getKey(),
            $eventType,
            $reason,
            $requestId,
            $createdAt,
        );

        $this->auditWriter->append(
            (string) $actor->getKey(),
            'product.assignment_'.$eventType,
            'product',
            (string) $product->getKey(),
            (string) $product->vendor_account_id,
            'allowed',
            'product_assignment_management',
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
