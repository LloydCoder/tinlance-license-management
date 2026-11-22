<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class MembershipDirectoryQuery
{
    public function __construct(private MembershipAuthorization $authorization) {}

    /** @return LengthAwarePaginator<int, VendorMembership> */
    public function vendorMembers(User $actor, VendorAccount $vendor): LengthAwarePaginator
    {
        if (! $this->authorization->vendorCan($actor, $vendor, 'admins.manage')) {
            throw new AuthorizationException('The vendor membership directory cannot be viewed by this actor.');
        }

        return VendorMembership::query()
            ->select([
                'id',
                'vendor_account_id',
                'user_id',
                'status',
                'joined_at',
            ])
            ->where('vendor_account_id', $vendor->getKey())
            ->where('status', 'active')
            ->whereHas('user', static fn (Builder $user) => $user->where('status', 'active'))
            ->whereHas('vendorAccount', static fn (Builder $tenant) => $tenant->where('status', 'active'))
            ->with([
                'user:id,name,email,status',
                'roles' => static fn (BelongsToMany $roles) => $roles->where('scope', 'vendor'),
            ])
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'vendor_members_'.$vendor->getKey().'_page');
    }

    /** @return LengthAwarePaginator<int, CustomerMembership> */
    public function customerMembers(User $actor, CustomerOrganization $organization): LengthAwarePaginator
    {
        if (! $this->authorization->customerCan($actor, $organization, 'customer.members.manage')) {
            throw new AuthorizationException('The customer membership directory cannot be viewed by this actor.');
        }

        return CustomerMembership::query()
            ->select([
                'id',
                'customer_organization_id',
                'user_id',
                'status',
                'joined_at',
            ])
            ->where('customer_organization_id', $organization->getKey())
            ->where('status', 'active')
            ->whereHas('user', static fn (Builder $user) => $user->where('status', 'active'))
            ->whereHas('customerOrganization', static function (Builder $tenant): void {
                $tenant
                    ->where('status', 'active')
                    ->whereHas('vendorAccount', static fn (Builder $vendor) => $vendor->where('status', 'active'));
            })
            ->with([
                'user:id,name,email,status',
                'roles' => static fn (BelongsToMany $roles) => $roles->where('scope', 'customer'),
            ])
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->paginate(25, ['*'], 'customer_members_'.$organization->getKey().'_page');
    }
}
