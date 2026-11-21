<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipAuthorization;

final readonly class VendorMembershipPolicy
{
    public function __construct(private MembershipAuthorization $authorization) {}

    public function view(User $user, VendorMembership $membership): bool
    {
        $currentMembership = VendorMembership::query()
            ->whereKey($membership->getKey())
            ->where('status', 'active')
            ->first();

        if ($currentMembership === null) {
            return false;
        }

        $vendor = VendorAccount::query()
            ->whereKey($currentMembership->vendor_account_id)
            ->first();

        return $vendor instanceof VendorAccount
            && $this->authorization->vendorCan($user, $vendor, 'admins.manage');
    }
}
