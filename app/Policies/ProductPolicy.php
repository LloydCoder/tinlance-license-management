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
        return $this->authorization->vendorCan($user, $vendor, 'products.manage');
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
            || ! $this->authorization->vendorCan($user, $vendor, 'products.manage')
        ) {
            return false;
        }

        return (string) $product->created_by_user_id === (string) $user->getKey()
            || $this->isVendorSuperAdmin($user, $vendor);
    }

    public function update(User $user, Product $product): bool
    {
        return ! $this->platformAuthorization->isBootstrapSuperAdmin($user)
            && $product->status === 'draft'
            && $this->view($user, $product);
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
