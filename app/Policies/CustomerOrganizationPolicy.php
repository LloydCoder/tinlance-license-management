<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerOrganization;
use App\Models\User;
use App\Models\VendorAccount;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\PlatformSuperAdminAuthorization;

final class CustomerOrganizationPolicy
{
    public function __construct(
        private readonly MembershipAuthorization $authorization,
        private readonly PlatformSuperAdminAuthorization $platformAuthorization,
    ) {}

    public function view(User $user, CustomerOrganization $organization): bool
    {
        $currentOrganization = CustomerOrganization::query()
            ->whereKey($organization->getKey())
            ->with('vendorAccount')
            ->first();

        if (! $currentOrganization instanceof CustomerOrganization) {
            return false;
        }

        if (
            $currentOrganization->status === 'active'
            && $this->authorization->customerCan($user, $currentOrganization, 'customer.licences.read')
        ) {
            return true;
        }

        $vendor = $currentOrganization->vendorAccount;

        if (! $vendor instanceof VendorAccount) {
            return false;
        }

        if (
            $currentOrganization->status === 'active'
            && $this->authorization->vendorCan($user, $vendor, 'customers.support.read')
        ) {
            return true;
        }

        if ($vendor->installation_key === 'primary') {
            return false;
        }

        return $this->platformAuthorization->canReadTenantResource(
            $user,
            $vendor,
            'customer_organization.read',
            'customer_organization',
            (string) $currentOrganization->getKey(),
            $currentOrganization->status === 'active',
        );
    }
}
