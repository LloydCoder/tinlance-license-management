<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerOrganization;
use App\Models\User;
use App\Models\VendorAccount;
use App\Services\Authorization\MembershipAuthorization;

final class CustomerOrganizationPolicy
{
    public function __construct(
        private readonly MembershipAuthorization $authorization,
    ) {}

    public function view(User $user, CustomerOrganization $organization): bool
    {
        $currentOrganization = CustomerOrganization::query()
            ->whereKey($organization->getKey())
            ->where('status', 'active')
            ->with('vendorAccount')
            ->first();

        if (! $currentOrganization instanceof CustomerOrganization) {
            return false;
        }

        if ($this->authorization->customerCan($user, $currentOrganization, 'customer.licences.read')) {
            return true;
        }

        $vendor = $currentOrganization->vendorAccount;

        return $vendor instanceof VendorAccount
            && $this->authorization->vendorCan($user, $vendor, 'customers.support.read');
    }
}
