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
            ->whereIn('status', ['active', 'invited'])
            ->first();

        if (
            $currentMembership === null
            || ! User::query()->whereKey($user->getKey())->where('status', 'active')->exists()
        ) {
            return false;
        }

        $vendor = VendorAccount::query()
            ->whereKey($currentMembership->vendor_account_id)
            ->where('status', 'active')
            ->first();

        if (! $vendor instanceof VendorAccount) {
            return false;
        }

        if ($currentMembership->user_id === $user->getKey()) {
            return true;
        }

        return $this->authorization->vendorCan($user, $vendor, 'admins.manage');
    }
}
