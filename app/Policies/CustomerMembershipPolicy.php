<?php

declare(strict_types=1);

namespace App\\Policies;

use App\\Models\\CustomerMembership;
use App\\Models\\CustomerOrganization;
use App\\Models\\User;
use App\\Services\\Authorization\\MembershipAuthorization;

final readonly class CustomerMembershipPolicy
{
    public function __construct(private MembershipAuthorization $authorization) {}

    public function view(User $user, CustomerMembership $membership): bool
    {
        $currentMembership = CustomerMembership::query()
            ->whereKey($membership->getKey())
            ->where('status', 'active')
            ->first();

        if ($currentMembership === null) {
            return false;
        }

        $organization = CustomerOrganization::query()
            ->whereKey($currentMembership->customer_organization_id)
            ->first();

        return $organization instanceof CustomerOrganization
            && $this->authorization->customerCan($user, $organization, 'customer.members.manage');
    }
}
