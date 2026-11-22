<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CustomerMembershipInvitation;
use App\Models\CustomerOrganization;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembershipInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class MembershipInvitationWorkspaceQuery
{
    public function __construct(private MembershipAuthorization $authorization) {}

    /** @return LengthAwarePaginator<int, VendorMembershipInvitation> */
    public function vendorInvitations(User $actor, VendorAccount $vendor): LengthAwarePaginator
    {
        if (! $this->authorization->vendorCan($actor, $vendor, 'admins.manage')) {
            throw new AuthorizationException('The vendor invitations cannot be viewed by this actor.');
        }

        return VendorMembershipInvitation::query()
            ->select([
                'id',
                'vendor_membership_id',
                'invited_by_user_id',
                'invited_email',
                'reason',
                'expires_at',
                'sent_at',
                'delivery_status',
                'created_at',
                'updated_at',
            ])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereHas('membership', static function (Builder $membership) use ($vendor): void {
                $membership
                    ->where('vendor_account_id', $vendor->getKey())
                    ->where('status', 'invited')
                    ->whereHas('vendorAccount', static fn (Builder $tenant) => $tenant->where('status', 'active'));
            })
            ->with([
                'membership:id,vendor_account_id,user_id,status',
                'membership.user:id,name,email,status',
                'membership.roles:id,scope,slug,name',
                'inviter:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'vendor_'.$vendor->getKey().'_page');
    }

    /** @return LengthAwarePaginator<int, CustomerMembershipInvitation> */
    public function customerInvitations(User $actor, CustomerOrganization $organization): LengthAwarePaginator
    {
        if (! $this->authorization->customerCan($actor, $organization, 'customer.members.manage')) {
            throw new AuthorizationException('The customer invitations cannot be viewed by this actor.');
        }

        return CustomerMembershipInvitation::query()
            ->select([
                'id',
                'customer_membership_id',
                'invited_by_user_id',
                'invited_email',
                'reason',
                'expires_at',
                'sent_at',
                'delivery_status',
                'created_at',
                'updated_at',
            ])
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereHas('membership', static function (Builder $membership) use ($organization): void {
                $membership
                    ->where('customer_organization_id', $organization->getKey())
                    ->where('status', 'invited')
                    ->whereHas('customerOrganization', static function (Builder $tenant) {
                        $tenant
                            ->where('status', 'active')
                            ->whereHas('vendorAccount', static fn (Builder $vendor) => $vendor->where('status', 'active'));
                    });
            })
            ->with([
                'membership:id,customer_organization_id,user_id,status',
                'membership.user:id,name,email,status',
                'membership.roles:id,scope,slug,name',
                'inviter:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'customer_'.$organization->getKey().'_page');
    }
}
