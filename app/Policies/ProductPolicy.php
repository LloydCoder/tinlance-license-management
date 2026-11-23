<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Models\VendorAccount;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformSuperAdminAuthorization;

final readonly class ProductPolicy
{
    public function __construct(
        private MembershipAuthorization $authorization,
        private PlatformSuperAdminAuthorization $platformAuthorization,
    ) {}

    public function viewAny(User $user, VendorAccount $vendor): bool
    {
        return $this->authorization->vendorCan($user, $vendor, 'products.read')
            || $this->authorization->vendorCan($user, $vendor, 'products.manage');
    }

    public function create(User $user, VendorAccount $vendor): bool
    {
        return ! $this->platformAuthorization->isBootstrapSuperAdmin($user)
            && $this->authorization->vendorCan($user, $vendor, 'products.manage');
    }

    public function view(User $user, Product $product): bool
    {
        $vendor = VendorAccount::query()
            ->whereKey($product->vendor_account_id)
            ->where('status', 'active')
            ->first();

        if (
            ! $vendor instanceof VendorAccount
            || (! $this->authorization->vendorCan($user, $vendor, 'products.read')
                && ! $this->authorization->vendorCan($user, $vendor, 'products.manage'))
        ) {
            return false;
        }

        return $this->isVendorSuperAdmin($user, $vendor)
            || $this->isAssigned($user, $product, $vendor);
    }

    public function update(User $user, Product $product): bool
    {
        $vendor = VendorAccount::query()->whereKey($product->vendor_account_id)->where('status', 'active')->first();

        return ! $this->platformAuthorization->isBootstrapSuperAdmin($user)
            && $product->status === 'draft'
            && $vendor instanceof VendorAccount
            && $this->authorization->vendorCan($user, $vendor, 'products.manage')
            && $this->view($user, $product);
    }

    public function manageAssignments(User $user, Product $product): bool
    {
        $vendor = VendorAccount::query()
            ->whereKey($product->vendor_account_id)
            ->where('status', 'active')
            ->first();

        return ! $this->platformAuthorization->isBootstrapSuperAdmin($user)
            && $vendor instanceof VendorAccount
            && $this->authorization->vendorCan($user, $vendor, 'products.manage')
            && $this->isVendorSuperAdmin($user, $vendor);
    }

    private function isAssigned(User $user, Product $product, VendorAccount $vendor): bool
    {
        $membership = $user->vendorMemberships()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('status', 'active')
            ->first();

        return $membership !== null
            && $product->assignments()
                ->where('vendor_membership_id', $membership->getKey())
                ->whereNull('revoked_at')
                ->exists();
    }

    private function isVendorSuperAdmin(User $user, VendorAccount $vendor): bool
    {
        return $user->vendorMemberships()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('status', 'active')
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();
    }
}
