<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\User;
use App\Services\Authorization\MembershipAuthorization;

final readonly class CustomerMembershipPolicy
{
    public function __construct(private MembershipAuthorization $authorization) {}

    public function view(User $user, CustomerMembership $membership): bool
    {
        $currentMembership = CustomerMembership::query()
            ->whereKey($membership->getKey())
            ->whereIn('status', ['active', 'invited'])
            ->first();

        if (
            $currentMembership === null
            || ! User::query()->whereKey($user->getKey())->where('status', 'active')->exists()
        ) {
            return false;
        }

        $organization = CustomerOrganization::query()
            ->whereKey($currentMembership->customer_organization_id)
            ->where('status', 'active')
            ->whereHas('vendorAccount', static fn ($query) => $query->where('status', 'active'))
            ->first();

        if (! $organization instanceof CustomerOrganization) {
            return false;
        }

        if ($currentMembership->user_id === $user->getKey()) {
            return true;
        }

        return $this->authorization->customerCan($user, $organization, 'customer.members.manage');
    }
}
