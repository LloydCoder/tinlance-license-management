<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CustomerMembership;
use App\Models\CustomerMembershipLifecycleEvent;
use App\Models\CustomerMembershipInvitation;
use App\Models\CustomerOrganization;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipLifecycleEvent;
use App\Models\VendorMembershipInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class MembershipLifecycleService
{
    public function __construct(private MembershipAuthorization $authorization) {}

    public function revokeVendorMembership(
        User $actor,
        VendorAccount $vendor,
        VendorMembership $membership,
        string $reason,
    ): bool {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new AuthorizationException('A valid membership revocation reason is required.');
        }

        return DB::transaction(function () use ($actor, $vendor, $membership, $reason): bool {
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
                ->lockForUpdate()
                ->first();

            if (
                $activeActor === null
                || $activeVendor === null
                || $actorMembership === null
                || ! $target instanceof VendorMembership
                || $target->user_id === $actor->getKey()
                || ! $this->authorization->vendorCan($actor, $vendor, 'admins.manage')
            ) {
                throw new AuthorizationException('The vendor membership cannot be revoked by this actor.');
            }

            if ($target->status === 'revoked') {
                return false;
            }

            if (! in_array($target->status, ['invited', 'active'], true)) {
                throw new AuthorizationException('The vendor membership is not in a revocable state.');
            }

            $fromStatus = $target->status;
            $target->forceFill(['status' => 'revoked'])->save();

            VendorMembershipInvitation::query()
                ->where('vendor_membership_id', $target->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            VendorMembershipLifecycleEvent::query()->create([
                'vendor_membership_id' => $target->getKey(),
                'actor_user_id' => $actor->getKey(),
                'from_status' => $fromStatus,
                'to_status' => 'revoked',
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return true;
        }, 3);
    }

    public function revokeCustomerMembership(
        User $actor,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        string $reason,
    ): bool {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new AuthorizationException('A valid membership revocation reason is required.');
        }

        return DB::transaction(function () use ($actor, $organization, $membership, $reason): bool {
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

            // Lock the persisted parent vendor as well as the organization. The
            // relationship predicate alone does not serialize a concurrent
            // vendor suspension against this membership mutation.
            $activeVendor = $activeOrganization instanceof CustomerOrganization
                ? VendorAccount::query()
                    ->whereKey($activeOrganization->vendor_account_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first()
                : null;

            $actorMembership = CustomerMembership::query()
                ->where('customer_organization_id', $organization->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $target = CustomerMembership::query()
                ->whereKey($membership->getKey())
                ->where('customer_organization_id', $organization->getKey())
                ->lockForUpdate()
                ->first();

            if (
                $activeActor === null
                || ! $activeOrganization instanceof CustomerOrganization
                || ! $activeVendor instanceof VendorAccount
                || $actorMembership === null
                || ! $target instanceof CustomerMembership
                || $target->user_id === $actor->getKey()
                || ! $this->authorization->customerCan($actor, $organization, 'customer.members.manage')
            ) {
                throw new AuthorizationException('The customer membership cannot be revoked by this actor.');
            }

            if ($target->status === 'revoked') {
                return false;
            }

            if (! in_array($target->status, ['invited', 'active'], true)) {
                throw new AuthorizationException('The customer membership is not in a revocable state.');
            }

            $fromStatus = $target->status;
            $target->forceFill(['status' => 'revoked'])->save();

            CustomerMembershipInvitation::query()
                ->where('customer_membership_id', $target->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            CustomerMembershipLifecycleEvent::query()->create([
                'customer_membership_id' => $target->getKey(),
                'actor_user_id' => $actor->getKey(),
                'from_status' => $fromStatus,
                'to_status' => 'revoked',
                'reason' => $reason,
                'created_at' => now(),
            ]);

            return true;
        }, 3);
    }
}
