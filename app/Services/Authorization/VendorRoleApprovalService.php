<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class VendorRoleApprovalService
{
    private const APPROVAL_REQUIRED_ROLES = [
        'finance_admin',
        'vendor_product_manager',
        'reseller_manager',
    ];

    public function __construct(
        private MembershipAuthorization $authorization,
        private MembershipRoleAssignmentService $membershipRoles,
    ) {}

    public function requestVendorRoleGrant(
        User $maker,
        VendorAccount $vendor,
        VendorMembership $targetMembership,
        Role $role,
        string $reason,
        string $idempotencyKey,
    ): ApprovalRequest {
        $reason = trim($reason);

        if (
            $reason === ''
            || mb_strlen($reason) > 500
            || preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/D', $idempotencyKey) !== 1
            || ! in_array($role->slug, self::APPROVAL_REQUIRED_ROLES, true)
        ) {
            throw new AuthorizationException('The requested privileged role approval is not permitted.');
        }

        return DB::transaction(function () use ($maker, $vendor, $targetMembership, $role, $reason, $idempotencyKey): ApprovalRequest {
            $payloadHash = $this->payloadHash(
                $maker->getKey(),
                $vendor->getKey(),
                $targetMembership->getKey(),
                $role->getKey(),
                $reason,
            );

            $activeMaker = User::query()
                ->whereKey($maker->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $activeVendor = VendorAccount::query()
                ->whereKey($vendor->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $makerMembership = VendorMembership::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('user_id', $maker->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $target = VendorMembership::query()
                ->whereKey($targetMembership->getKey())
                ->where('vendor_account_id', $vendor->getKey())
                ->whereIn('status', ['invited', 'active'])
                ->lockForUpdate()
                ->first();

            $targetUser = $target === null
                ? null
                : User::query()
                    ->whereKey($target->user_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

            $currentRole = Role::query()
                ->whereKey($role->getKey())
                ->where('scope', 'vendor')
                ->whereIn('slug', self::APPROVAL_REQUIRED_ROLES)
                ->lockForUpdate()
                ->first();

            if (
                $activeMaker === null
                || $activeVendor === null
                || $makerMembership === null
                || $target === null
                || $targetUser === null
                || $currentRole === null
                || $target->user_id === $maker->getKey()
                || ! $this->authorization->vendorCan($maker, $vendor, 'admins.manage')
            ) {
                throw new AuthorizationException('The requested privileged role approval is not permitted.');
            }

            $existing = ApprovalRequest::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ApprovalRequest) {
                if (
                    $existing->requested_by_user_id !== $maker->getKey()
                    || ! hash_equals($existing->payload_hash, $payloadHash)
                ) {
                    throw new ConflictHttpException('The idempotency key was already used for a different approval request.');
                }

                if ($existing->status === 'pending' && Carbon::parse($existing->expires_at)->isPast()) {
                    return $this->expire($existing);
                }

                return $existing;
            }

            if ($target->roles()->whereKey($currentRole->getKey())->exists()) {
                throw new ConflictHttpException('The target membership already has this role.');
            }

            $pending = ApprovalRequest::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('target_vendor_membership_id', $target->getKey())
                ->where('role_id', $currentRole->getKey())
                ->where('action', 'vendor_role.assign')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($pending instanceof ApprovalRequest) {
                if (Carbon::parse($pending->expires_at)->isPast()) {
                    $this->expire($pending);
                } else {
                    throw new ConflictHttpException('A pending approval already exists for this membership and role.');
                }
            }

            return ApprovalRequest::query()->create([
                'vendor_account_id' => $vendor->getKey(),
                'target_vendor_membership_id' => $target->getKey(),
                'role_id' => $currentRole->getKey(),
                'action' => 'vendor_role.assign',
                'status' => 'pending',
                'requested_by_user_id' => $maker->getKey(),
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'expires_at' => now()->addSeconds(max(300, (int) config('tlms.approvals.ttl_seconds', 86400))),
            ]);
        }, 3);
    }

    public function approveVendorRoleGrant(
        User $checker,
        VendorAccount $vendor,
        ApprovalRequest $approvalRequest,
        string $decisionReason,
    ): ApprovalRequest {
        $decisionReason = trim($decisionReason);

        if ($decisionReason === '' || mb_strlen($decisionReason) > 500) {
            throw new AuthorizationException('A valid approval decision reason is required.');
        }

        return DB::transaction(function () use ($checker, $vendor, $approvalRequest, $decisionReason): ApprovalRequest {
            $request = $this->lockedRequest($vendor, $approvalRequest);

            $this->authorizeChecker($checker, $vendor, $request);
            $this->assertPayloadIntegrity($request);

            if ($request->status === 'approved') {
                return $request;
            }

            if ($request->status !== 'pending') {
                throw new ConflictHttpException('This approval request is no longer pending.');
            }

            if (Carbon::parse($request->expires_at)->isPast()) {
                return $this->expire($request);
            }

            $target = VendorMembership::query()
                ->whereKey($request->target_vendor_membership_id)
                ->where('vendor_account_id', $vendor->getKey())
                ->whereIn('status', ['invited', 'active'])
                ->lockForUpdate()
                ->first();

            $role = Role::query()
                ->whereKey($request->role_id)
                ->where('scope', 'vendor')
                ->whereIn('slug', self::APPROVAL_REQUIRED_ROLES)
                ->lockForUpdate()
                ->first();

            if (! $target instanceof VendorMembership || ! $role instanceof Role) {
                throw new ModelNotFoundException('Approval target or role no longer exists.');
            }

            $auditReason = mb_substr(
                'Approved request '.$request->getKey().'; maker: '.$request->reason.'; checker: '.$decisionReason,
                0,
                500,
            );

            $changed = $this->membershipRoles->assignVendorRoleAfterApproval(
                $checker,
                $vendor,
                $target,
                $role,
                $auditReason,
                $request,
            );

            if (! $changed) {
                throw new ConflictHttpException('The requested role grant could not be applied.');
            }

            $request->transitionTo('approved', $checker->getKey(), $decisionReason);
            ApprovalDecision::query()->create([
                'approval_request_id' => $request->getKey(),
                'actor_user_id' => $checker->getKey(),
                'decision' => 'approved',
                'reason' => $decisionReason,
                'created_at' => now(),
            ]);

            $request->refresh();

            return $request;
        }, 3);
    }

    public function rejectVendorRoleGrant(
        User $checker,
        VendorAccount $vendor,
        ApprovalRequest $approvalRequest,
        string $decisionReason,
    ): ApprovalRequest {
        $decisionReason = trim($decisionReason);

        return DB::transaction(function () use ($checker, $vendor, $approvalRequest, $decisionReason): ApprovalRequest {
            $request = $this->lockedRequest($vendor, $approvalRequest);

            $this->authorizeChecker($checker, $vendor, $request);
            $this->assertPayloadIntegrity($request);

            if ($request->status === 'rejected') {
                return $request;
            }

            if ($request->status !== 'pending') {
                throw new ConflictHttpException('This approval request is no longer pending.');
            }

            if (Carbon::parse($request->expires_at)->isPast()) {
                return $this->expire($request);
            }

            $request->transitionTo('rejected', $checker->getKey(), $decisionReason);
            ApprovalDecision::query()->create([
                'approval_request_id' => $request->getKey(),
                'actor_user_id' => $checker->getKey(),
                'decision' => 'rejected',
                'reason' => $decisionReason,
                'created_at' => now(),
            ]);

            $request->refresh();

            return $request;
        }, 3);
    }

    /** @return LengthAwarePaginator<int, ApprovalRequest> */
    public function listVendorRoleGrants(
        User $viewer,
        VendorAccount $vendor,
        string $status = 'pending',
    ): LengthAwarePaginator {
        $canReview = $this->authorization->vendorCan($viewer, $vendor, 'approvals.review')
            && $this->authorization->vendorCan($viewer, $vendor, 'admins.manage');
        $canManage = $this->authorization->vendorCan($viewer, $vendor, 'admins.manage');

        if (! $canReview && ! $canManage) {
            throw new AuthorizationException('The actor cannot view vendor role approval requests.');
        }

        return DB::transaction(function () use ($viewer, $vendor, $status, $canReview): LengthAwarePaginator {
            $expiredRequests = ApprovalRequest::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->where('status', 'pending')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($expiredRequests as $expiredRequest) {
                $this->expire($expiredRequest);
            }

            $query = ApprovalRequest::query()
                ->with(['role', 'targetMembership.user', 'requester', 'decider'])
                ->where('vendor_account_id', $vendor->getKey());

            if (! $canReview) {
                $query->where('requested_by_user_id', $viewer->getKey());
            }

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            return $query
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25);
        }, 3);
    }

    public function viewVendorRoleGrant(
        User $viewer,
        VendorAccount $vendor,
        ApprovalRequest $approvalRequest,
    ): ApprovalRequest {
        return DB::transaction(function () use ($viewer, $vendor, $approvalRequest): ApprovalRequest {
            $request = $this->lockedRequest($vendor, $approvalRequest);

            if ($request->status === 'pending' && Carbon::parse($request->expires_at)->isPast()) {
                $request = $this->expire($request);
            }

            if (
                $request->requested_by_user_id !== $viewer->getKey()
                && ! ($this->authorization->vendorCan($viewer, $vendor, 'approvals.review') && $this->authorization->vendorCan($viewer, $vendor, 'admins.manage'))
            ) {
                throw new AuthorizationException('The approval request is not visible to this actor.');
            }

            return $request->load(['targetMembership.user', 'role', 'requester', 'decider', 'decisions.actor']);
        }, 3);
    }

    private function lockedRequest(VendorAccount $vendor, ApprovalRequest $approvalRequest): ApprovalRequest
    {
        return ApprovalRequest::query()
            ->whereKey($approvalRequest->getKey())
            ->where('vendor_account_id', $vendor->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function authorizeChecker(User $checker, VendorAccount $vendor, ApprovalRequest $request): void
    {
        if ($request->requested_by_user_id === $checker->getKey()) {
            throw new AuthorizationException('The request maker cannot approve or reject their own request.');
        }

        $activeChecker = User::query()
            ->whereKey($checker->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        $activeVendor = VendorAccount::query()
            ->whereKey($vendor->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        $checkerMembership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $checker->getKey())
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if (
            $activeChecker === null
            || $activeVendor === null
            || $checkerMembership === null
            || ! $this->authorization->vendorCan($checker, $vendor, 'approvals.review')
            || ! $this->authorization->vendorCan($checker, $vendor, 'admins.manage')
        ) {
            throw new AuthorizationException('The actor is not an authorized approval checker for this vendor.');
        }
    }

    private function assertPayloadIntegrity(ApprovalRequest $request): void
    {
        $expectedHash = $this->payloadHash(
            $request->requested_by_user_id,
            $request->vendor_account_id,
            $request->target_vendor_membership_id,
            $request->role_id,
            $request->reason,
        );

        if ($request->action !== 'vendor_role.assign' || ! hash_equals($request->payload_hash, $expectedHash)) {
            throw new ConflictHttpException('Approval request integrity verification failed.');
        }
    }

    private function expire(ApprovalRequest $request): ApprovalRequest
    {
        $reason = 'Approval request expired before a human decision was recorded.';
        $request->transitionTo('expired', null, $reason);

        ApprovalDecision::query()->create([
            'approval_request_id' => $request->getKey(),
            'actor_user_id' => null,
            'decision' => 'expired',
            'reason' => $reason,
            'created_at' => now(),
        ]);

        $request->refresh();
        return $request;
    }

    private function payloadHash(
        string $makerId,
        string $vendorId,
        string $membershipId,
        string $roleId,
        string $reason,
    ): string {
        return hash('sha256', json_encode([
            'action' => 'vendor_role.assign',
            'maker_user_id' => $makerId,
            'vendor_account_id' => $vendorId,
            'target_vendor_membership_id' => $membershipId,
            'role_id' => $roleId,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));
    }
}
