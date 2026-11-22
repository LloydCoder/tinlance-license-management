<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VendorAccount;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformSuperAdminAuthorization;

final class VendorAccountPolicy
{
    public function __construct(
        private readonly MembershipAuthorization $authorization,
        private readonly PlatformSuperAdminAuthorization $platformAuthorization,
    ) {}

    public function view(User $user, VendorAccount $vendor): bool
    {
        if ($this->authorization->vendorCan($user, $vendor, 'vendor.account.read')) {
            return true;
        }

        $currentVendor = VendorAccount::query()->whereKey($vendor->getKey())->first();

        if (! $currentVendor instanceof VendorAccount || $currentVendor->installation_key === 'primary') {
            return false;
        }

        return $this->platformAuthorization->canReadTenantResource(
            $user,
            $currentVendor,
            'vendor.read',
            'vendor_account',
            (string) $currentVendor->getKey(),
            $currentVendor->status === 'active',
        );
    }
}
