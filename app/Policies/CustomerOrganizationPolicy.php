<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerOrganization;
use App\Models\User;
use App\Services\Authorization\MembershipAuthorization;

final class CustomerOrganizationPolicy
{
    public function view(
        User $user,
        CustomerOrganization $organization,
        MembershipAuthorization $authorization,
    ): bool {
        return $authorization->customerCan($user, $organization, 'customer.licences.read')
            || $authorization->vendorCan(
                $user,
                $organization->vendorAccount,
                'customers.support.read',
            );
    }
}
