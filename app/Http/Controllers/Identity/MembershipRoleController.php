<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipRoleAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class MembershipRoleController
{
    public function assignVendor(
        Request $request,
        VendorAccount $vendor,
        VendorMembership $membership,
        Role $role,
        MembershipRoleAssignmentService $service,
    ): JsonResponse {
        return $this->changeVendorRole($request, $vendor, $membership, $role, $service, 'assigned');
    }

    public function revokeVendor(
        Request $request,
        VendorAccount $vendor,
        VendorMembership $membership,
        Role $role,
        MembershipRoleAssignmentService $service,
    ): JsonResponse {
        return $this->changeVendorRole($request, $vendor, $membership, $role, $service, 'revoked');
    }

    public function assignCustomer(
        Request $request,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        Role $role,
        MembershipRoleAssignmentService $service,
    ): JsonResponse {
        return $this->changeCustomerRole($request, $organization, $membership, $role, $service, 'assigned');
    }

    public function revokeCustomer(
        Request $request,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        Role $role,
        MembershipRoleAssignmentService $service,
    ): JsonResponse {
        return $this->changeCustomerRole($request, $organization, $membership, $role, $service, 'revoked');
    }

    private function changeVendorRole(
        Request $request,
        VendorAccount $vendor,
        VendorMembership $membership,
        Role $role,
        MembershipRoleAssignmentService $service,
        string $action,
    ): JsonResponse {
        abort_unless($membership->vendor_account_id === $vendor->getKey(), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('view', $membership);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $changed = $action === 'assigned'
            ? $service->assignVendorRole($actor, $vendor, $membership, $role, $validated['reason'])
            : $service->revokeVendorRole($actor, $vendor, $membership, $role, $validated['reason']);

        return $this->result($membership->getKey(), $role->getKey(), $action, $changed);
    }

    private function changeCustomerRole(
        Request $request,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        Role $role,
        MembershipRoleAssignmentService $service,
        string $action,
    ): JsonResponse {
        abort_unless($membership->customer_organization_id === $organization->getKey(), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('view', $membership);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $changed = $action === 'assigned'
            ? $service->assignCustomerRole($actor, $organization, $membership, $role, $validated['reason'])
            : $service->revokeCustomerRole($actor, $organization, $membership, $role, $validated['reason']);

        return $this->result($membership->getKey(), $role->getKey(), $action, $changed);
    }

    private function result(string $membershipId, string $roleId, string $action, bool $changed): JsonResponse
    {
        return response()->json([
            'data' => [
                'membership_id' => $membershipId,
                'role_id' => $roleId,
                'action' => $action,
                'changed' => $changed,
            ],
        ], $action === 'assigned' && $changed ? 201 : 200);
    }
}
