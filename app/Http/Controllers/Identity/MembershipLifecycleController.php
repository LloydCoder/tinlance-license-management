<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\CustomerMembership;
use App\Models\CustomerOrganization;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\MembershipLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class MembershipLifecycleController
{
    public function revokeVendor(
        Request $request,
        VendorAccount $vendor,
        VendorMembership $membership,
        MembershipLifecycleService $service,
    ): JsonResponse {
        abort_unless($membership->vendor_account_id === $vendor->getKey(), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $changed = $service->revokeVendorMembership($actor, $vendor, $membership, $validated['reason']);

        return response()->json([
            'data' => [
                'membership_id' => $membership->getKey(),
                'status' => 'revoked',
                'changed' => $changed,
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    public function revokeCustomer(
        Request $request,
        CustomerOrganization $organization,
        CustomerMembership $membership,
        MembershipLifecycleService $service,
    ): JsonResponse {
        abort_unless($membership->customer_organization_id === $organization->getKey(), 404);

        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $changed = $service->revokeCustomerMembership($actor, $organization, $membership, $validated['reason']);

        return response()->json([
            'data' => [
                'membership_id' => $membership->getKey(),
                'status' => 'revoked',
                'changed' => $changed,
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    private function requestId(Request $request): string
    {
        $candidate = $request->headers->get('X-Request-ID');

        return is_string($candidate)
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $candidate) === 1
                ? $candidate
                : (string) Str::uuid();
    }
}
