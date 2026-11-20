<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\Permission;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;

final class MembershipAuthorization
{
    public function vendorCan(User $user, VendorAccount $vendor, string $permission): bool
    {
        if ($permission === ''
            || ! User::query()->whereKey($user->getKey())->where('status', 'active')->exists()
            || ! VendorAccount::query()->whereKey($vendor->getKey())->where('status', 'active')->exists()
        ) {
            return false;
        }

        $membership = $user->vendorMemberships()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('status', 'active')
            ->with('roles.permissions')
            ->first();

        return $this->membershipHasPermission($membership, 'vendor', $permission);
    }

    public function customerCan(
        User $user,
        CustomerOrganization $organization,
        string $permission,
    ): bool {
        if (
            $permission === ''
            || ! User::query()->whereKey($user->getKey())->where('status', 'active')->exists()
            || ! CustomerOrganization::query()
                ->whereKey($organization->getKey())
                ->where('status', 'active')
                ->whereHas('vendorAccount', static fn ($query) => $query->where('status', 'active'))
                ->exists()
        ) {
            return false;
        }

        $membership = $user->customerMemberships()
            ->where('customer_organization_id', $organization->getKey())
            ->where('status', 'active')
            ->with('roles.permissions')
            ->first();

        return $this->membershipHasPermission($membership, 'customer', $permission);
    }

    private function membershipHasPermission(
        VendorMembership|CustomerMembership|null $membership,
        string $requiredScope,
        string $permission,
    ): bool {
        if ($membership === null) {
            return false;
        }

        foreach ($membership->roles as $role) {
            if (
                $role->scope === $requiredScope
                && $role->permissions->contains(
                    static fn (Permission $assignedPermission): bool => (
                        $assignedPermission->scope === $requiredScope
                        && $assignedPermission->name === $permission
                    ),
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
