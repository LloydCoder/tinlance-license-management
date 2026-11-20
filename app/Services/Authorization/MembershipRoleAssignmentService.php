<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CustomerMembership;
use App\Models\CustomerMembershipRoleEvent;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipRoleEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class MembershipRoleAssignmentService
{
    private const ASSIGNABLE_VENDOR_ROLES = [
        'support_agent',
        'security_auditor',
        'vendor_developer',
    ];

    private const ASSIGNABLE_CUSTOMER_ROLES = [
        'customer_billing_admin',
        'customer_technical_admin',
        'customer_member',
    ];

    public function __construct(private MembershipAuthorization $authorization) {}

    public function assignVendorRole(
        User $actor,
        VendorAccount $vendor,
        VendorMembership $membership,
        Role $role,
        string $reason,
    ): bool {
        return $this->mutateVendorRole($actor, $vendor, $membership, $role, $reason, 'assigned');
    }

    public function revokeVendorRole(
        User $actor,
        VendorAccount $vendor,
        VendorMembership $membership,
        Role $role,
        string $reason,
    ): bool {
        return $this->mutateVendorRole($actor, $vendor, $membership, $role, $reason, 'revoked');
    }

    public function assignCustomerRole(
        User $actor,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        Role $role,
        string $reason,
    ): bool {
        return $this->mutateCustomerRole($actor, $organization, $membership, $role, $reason, 'assigned');
    }

    public function revokeCustomerRole(
        User $actor,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        Role $role,
        string $reason,
    ): bool {
        return $this->mutateCustomerRole($actor, $organization, $membership, $role, $reason, 'revoked');
    }

    private function mutateVendorRole(
        User $actor,
        VendorAccount $vendor,
        VendorMembership $membership,
        Role $role,
        string $reason,
        string $action,
    ): bool {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500 || ! in_array($role->slug, self::ASSIGNABLE_VENDOR_ROLES, true)) {
            throw new AuthorizationException('The requested vendor role change is not permitted.');
        }

        return DB::transaction(function () use ($actor, $vendor, $membership, $role, $reason, $action): bool {
            $activeActor = User::query()
                ->whereKey($actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $activeVendor = VendorAccount::query()
                ->whereKey($vendor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $actorMembership = VendorMembership::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $target = VendorMembership::query()
                ->whereKey($membership->getKey())
                ->where('vendor_account_id', $vendor->getKey())
                ->whereIn('status', ['invited', 'active'])
                ->lockForUpdate()
                ->first();

            $targetUser = $target === null
                ? null
                : User::query()
                    ->whereKey($target->user_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

            $currentRole = Role::query()
                ->whereKey($role->getKey())
                ->where('scope', 'vendor')
                ->whereIn('slug', self::ASSIGNABLE_VENDOR_ROLES)
                ->lockForUpdate()
                ->first();

            if (
                $activeActor === null
                || $activeVendor === null
                || $actorMembership === null
                || $target === null
                || $targetUser === null
                || $currentRole === null
                || $target->user_id === $actor->getKey()
                || ! $this->authorization->vendorCan($actor, $vendor, 'admins.manage')
            ) {
                throw new AuthorizationException('The requested vendor role change is not permitted.');
            }

            $alreadyAssigned = $target->roles()->whereKey($currentRole->getKey())->exists();

            if ($action === 'assigned' && $alreadyAssigned) {
                return false;
            }

            if ($action === 'revoked' && ! $alreadyAssigned) {
                return false;
            }

            if ($action === 'assigned') {
                $target->roles()->attach($currentRole->getKey());
            } else {
                $target->roles()->detach($currentRole->getKey());
            }

            VendorMembershipRoleEvent::query()->create([
                'vendor_membership_id' => $target->getKey(),
                'role_id' => $currentRole->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => $action,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function mutateCustomerRole(
        User $actor,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        Role $role,
        string $reason,
        string $action,
    ): bool {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500 || ! in_array($role->slug, self::ASSIGNABLE_CUSTOMER_ROLES, true)) {
            throw new AuthorizationException('The requested customer role change is not permitted.');
        }

        return DB::transaction(function () use ($actor, $organization, $membership, $role, $reason, $action): bool {
            $activeActor = User::query()
                ->whereKey($actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $activeOrganization = CustomerOrganization::query()
                ->whereKey($organization->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $activeVendor = $activeOrganization === null
                ? null
                : VendorAccount::query()
                    ->whereKey($activeOrganization->vendor_account_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

            $actorMembership = CustomerMembership::query()
                ->where('customer_organization_id', $organization->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $target = CustomerMembership::query()
                ->whereKey($membership->getKey())
                ->where('customer_organization_id', $organization->getKey())
                ->whereIn('status', ['invited', 'active'])
                ->lockForUpdate()
                ->first();

            $targetUser = $target === null
                ? null
                : User::query()
                    ->whereKey($target->user_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

            $currentRole = Role::query()
                ->whereKey($role->getKey())
                ->where('scope', 'customer')
                ->whereIn('slug', self::ASSIGNABLE_CUSTOMER_ROLES)
                ->lockForUpdate()
                ->first();

            if (
                $activeActor === null
                || $activeOrganization === null
                || $activeVendor === null
                || $actorMembership === null
                || $target === null
                || $targetUser === null
                || $currentRole === null
                || $target->user_id === $actor->getKey()
                || ! $this->authorization->customerCan($actor, $organization, 'customer.members.manage')
            ) {
                throw new AuthorizationException('The requested customer role change is not permitted.');
            }

            $alreadyAssigned = $target->roles()->whereKey($currentRole->getKey())->exists();

            if ($action === 'assigned' && $alreadyAssigned) {
                return false;
            }

            if ($action === 'revoked' && ! $alreadyAssigned) {
                return false;
            }

            if ($action === 'assigned') {
                $target->roles()->attach($currentRole->getKey());
            } else {
                $target->roles()->detach($currentRole->getKey());
            }

            CustomerMembershipRoleEvent::query()->create([
                'customer_membership_id' => $target->getKey(),
                'role_id' => $currentRole->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => $action,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return true;
        }, 3);
    }
}
