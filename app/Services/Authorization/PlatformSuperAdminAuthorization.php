<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\PlatformAccessAuditEvent;
use App\Models\PlatformInstallation;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Illuminate\Http\Request;

final readonly class PlatformSuperAdminAuthorization
{
    public function __construct(
        private Request $request,
        private PlatformAccessAuditChainWriter $auditWriter,
    ) {}

    public function canReadTenantResource(
        User $actor,
        VendorAccount $tenantVendor,
        string $action,
        string $resourceType,
        string $resourceId,
        bool $resourceActive,
    ): bool {
        if (! $this->isBootstrapSuperAdmin($actor)) {
            return false;
        }

        $currentTenant = VendorAccount::query()->whereKey($tenantVendor->getKey())->first();
        $tenantActive = $currentTenant instanceof VendorAccount && $currentTenant->status === 'active';
        $allowed = $tenantActive && $resourceActive;

        $candidateRequestId = $this->request->headers->get('X-Request-ID');
        $requestId = is_string($candidateRequestId)
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $candidateRequestId) === 1
                ? $candidateRequestId
                : bin2hex(random_bytes(16));

        $this->auditWriter->append(
            (string) $actor->getKey(),
            $action,
            $resourceType,
            $resourceId,
            (string) $tenantVendor->getKey(),
            $allowed ? 'allowed' : 'denied',
            $allowed ? 'platform_super_admin' : ($tenantActive ? 'resource_inactive' : 'tenant_inactive'),
            $requestId,
        );

        return $allowed;
    }

    private function isBootstrapSuperAdmin(User $actor): bool
    {
        if (! User::query()->whereKey($actor->getKey())->where('status', 'active')->exists()) {
            return false;
        }

        $installation = PlatformInstallation::query()
            ->where('installation_key', 'primary')
            ->where('bootstrapped_user_id', $actor->getKey())
            ->whereNotNull('bootstrapped_at')
            ->exists();

        if (! $installation) {
            return false;
        }

        return VendorMembership::query()
            ->where('user_id', $actor->getKey())
            ->where('status', 'active')
            ->whereHas('vendorAccount', static fn ($query) => $query
                ->where('installation_key', 'primary')
                ->where('status', 'active'))
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();
    }
}
