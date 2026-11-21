<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\ApprovalRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\VendorRoleApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class VendorRoleApprovalController
{
    public function store(
        Request $request,
        VendorAccount $vendor,
        VendorMembership $membership,
        VendorRoleApprovalService $approvals,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'role_id' => ['required', 'string', 'max:26'],
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $idempotencyKey = $request->headers->get('Idempotency-Key');

        if (
            ! is_string($idempotencyKey)
            || preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/D', $idempotencyKey) !== 1
        ) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['A valid 16–128 character Idempotency-Key header is required.'],
            ]);
        }

        $role = Role::query()->whereKey($validated['role_id'])->firstOrFail();
        $approval = $approvals->requestVendorRoleGrant(
            $actor,
            $vendor,
            $membership,
            $role,
            $validated['reason'],
            $idempotencyKey,
        );

        return response()->json([
            'data' => $this->data($approval),
            'request_id' => $this->requestId($request),
        ], $approval->wasRecentlyCreated ? 202 : 200);
    }

    public function index(
        Request $request,
        VendorAccount $vendor,
        VendorRoleApprovalService $approvals,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected,expired,all'],
        ]);

        $paginator = $approvals->listVendorRoleGrants(
            $actor,
            $vendor,
            (string) ($validated['status'] ?? 'pending'),
        );

        $data = [];

        foreach ($paginator->items() as $approval) {
            $data[] = $this->data($approval);
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'request_id' => $this->requestId($request),
        ]);
    }

    public function show(
        Request $request,
        VendorAccount $vendor,
        ApprovalRequest $approvalRequest,
        VendorRoleApprovalService $approvals,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $approval = $approvals->viewVendorRoleGrant($actor, $vendor, $approvalRequest);

        return response()->json([
            'data' => $this->data($approval),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function approve(
        Request $request,
        VendorAccount $vendor,
        ApprovalRequest $approvalRequest,
        VendorRoleApprovalService $approvals,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $approval = $approvals->approveVendorRoleGrant($actor, $vendor, $approvalRequest, $validated['reason']);

        if ($approval->status === 'expired') {
            return $this->expiredProblem($request);
        }

        return response()->json([
            'data' => $this->data($approval),
            'request_id' => $this->requestId($request),
        ]);
    }

    public function reject(
        Request $request,
        VendorAccount $vendor,
        ApprovalRequest $approvalRequest,
        VendorRoleApprovalService $approvals,
    ): JsonResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        $approval = $approvals->rejectVendorRoleGrant($actor, $vendor, $approvalRequest, $validated['reason']);

        if ($approval->status === 'expired') {
            return $this->expiredProblem($request);
        }

        return response()->json([
            'data' => $this->data($approval),
            'request_id' => $this->requestId($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ApprovalRequest $approval): array
    {
        $approval->loadMissing(['role', 'targetMembership.user']);

        return [
            'id' => $approval->getKey(),
            'status' => $approval->status,
            'action' => $approval->action,
            'membership_id' => $approval->target_vendor_membership_id,
            'target_user_id' => $approval->targetMembership?->user?->getKey(),
            'target_user_name' => $approval->targetMembership?->user?->name,
            'role_id' => $approval->role_id,
            'role_slug' => $approval->role?->slug,
            'requested_by_user_id' => $approval->requested_by_user_id,
            'decided_by_user_id' => $approval->decided_by_user_id,
            'reason' => $approval->reason,
            'decision_reason' => $approval->decision_reason,
            'expires_at' => Carbon::parse($approval->expires_at)->toIso8601String(),
            'decided_at' => $approval->decided_at !== null ? Carbon::parse($approval->decided_at)->toIso8601String() : null,
        ];
    }

    private function expiredProblem(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => 'Approval request expired',
            'status' => 410,
            'detail' => 'The approval request expired before a human decision was recorded.',
            'code' => 'APPROVAL_EXPIRED',
            'request_id' => $this->requestId($request),
        ], 410, ['Content-Type' => 'application/problem+json']);
    }

    private function requestId(Request $request): string
    {
        $candidate = $request->headers->get('X-Request-ID');

        return is_string($candidate)
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $candidate) === 1
                ? $candidate
                : bin2hex(random_bytes(16));
    }
}
