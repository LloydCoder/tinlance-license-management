<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VendorAccount;
use App\Services\Authorization\MembershipAuthorization;

final class VendorAccountPolicy
{
    public function __construct(
        private readonly MembershipAuthorization $authorization,
    ) {}

    public function view(User $user, VendorAccount $vendor): bool
    {
        return $this->authorization->vendorCan($user, $vendor, 'vendor.account.read');
    }
}
