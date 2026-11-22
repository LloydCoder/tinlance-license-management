<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\User;
use App\Services\Authorization\MembershipAuthorization;
use App\Services\Authorization\MembershipDirectoryQuery;
use App\Services\Authorization\MembershipInvitationWorkspaceQuery;
use App\Services\Authorization\MembershipRoleAssignmentService;
use App\Services\Authorization\VendorAccountProfileManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WorkspaceController
{
    public function __invoke(
        Request $request,
        MembershipAuthorization $authorization,
        MembershipInvitationWorkspaceQuery $invitations,
        MembershipDirectoryQuery $directory,
        MembershipRoleAssignmentService $roles,
        VendorAccountProfileManagementService $vendorProfiles,
    ): Response|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User && $user->status === 'active', 403);

        $isSuperAdmin = $user->vendorMemberships()
            ->where('status', 'active')
            ->whereHas('vendorAccount', static fn ($query) => $query->where('status', 'active'))
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();

        if ($isSuperAdmin) {
            return redirect('/admin');
        }

        $vendorMemberships = $user->vendorMemberships()
            ->where('status', 'active')
            ->whereHas('vendorAccount', static fn ($query) => $query->where('status', 'active'))
            ->with(['vendorAccount', 'roles'])
            ->orderBy('created_at')
            ->get();

        $customerMemberships = $user->customerMemberships()
            ->where('status', 'active')
            ->whereHas('customerOrganization', static fn ($query) => $query
                ->where('status', 'active')
                ->whereHas('vendorAccount', static fn ($vendorQuery) => $vendorQuery->where('status', 'active')))
            ->with(['customerOrganization.vendorAccount', 'roles'])
            ->orderBy('created_at')
            ->get();

        $vendorInvitationGroups = [];
        foreach ($vendorMemberships as $membership) {
            $vendor = $membership->vendorAccount;
            if ($vendor === null || ! $authorization->vendorCan($user, $vendor, 'admins.manage')) {
                continue;
            }

            $vendorInvitationGroups[] = [
                'tenant' => $vendor,
                'members' => $directory->vendorMembers($user, $vendor),
                'invitations' => $invitations->vendorInvitations($user, $vendor),
                'revokedMemberships' => $invitations->vendorRevokedMemberships($user, $vendor),
                'roles' => $roles->assignableVendorRoles(),
                'revocableRoles' => $roles->revocableVendorRoles(),
            ];
        }

        $customerInvitationGroups = [];
        foreach ($customerMemberships as $membership) {
            $organization = $membership->customerOrganization;
            if ($organization === null || ! $authorization->customerCan($user, $organization, 'customer.members.manage')) {
                continue;
            }

            $customerInvitationGroups[] = [
                'tenant' => $organization,
                'members' => $directory->customerMembers($user, $organization),
                'invitations' => $invitations->customerInvitations($user, $organization),
                'revokedMemberships' => $invitations->customerRevokedMemberships($user, $organization),
                'roles' => $roles->assignableCustomerRoles(),
                'revocableRoles' => $roles->revocableCustomerRoles(),
            ];
        }

        $vendorProfileVendor = $vendorProfiles->manageableVendor($user);

        $now = (int) now()->getTimestamp();
        $passwordConfirmedAt = $request->session()->get('auth.password_confirmed_at');
        $passwordTimestamp = is_numeric($passwordConfirmedAt) ? (int) $passwordConfirmedAt : null;
        $passwordTimeout = max(1, (int) config('auth.password_timeout', 10800));
        $passwordConfirmationFresh = $passwordTimestamp !== null
            && $passwordTimestamp <= $now
            && $passwordTimestamp >= ($now - $passwordTimeout);

        $stepUpAt = $request->session()->get('auth.mfa_step_up_at');
        $stepUpMethod = $request->session()->get('auth.mfa_step_up_method');
        $stepUpTimestamp = is_numeric($stepUpAt) ? (int) $stepUpAt : null;
        $stepUpTtl = max(1, (int) config('tlms.security.step_up_ttl_seconds', 300));
        $mfaStepUpFresh = $stepUpTimestamp !== null
            && $stepUpTimestamp <= $now
            && $stepUpTimestamp >= ($now - $stepUpTtl)
            && is_string($stepUpMethod)
            && in_array($stepUpMethod, ['totp', 'passkey'], true);

        $hasTotp = $user->hasEnabledTwoFactorAuthentication();
        $hasPasskeys = $user->hasPasskeysEnabled();
        $hasManageableTenants = $vendorInvitationGroups !== [] || $customerInvitationGroups !== [];

        if ($hasManageableTenants && ! $passwordConfirmationFresh) {
            $request->session()->put('url.intended', route('workspace.home'));
        }

        return response()->view('workspace.dashboard', [
            'user' => $user,
            'mfaRecoveryCodeReplacement' => $request->session()->get('mfa.recovery_code_replacement_once'),
            'vendorMemberships' => $vendorMemberships,
            'vendorProfileVendor' => $vendorProfileVendor,
            'customerMemberships' => $customerMemberships,
            'vendorInvitationGroups' => $vendorInvitationGroups,
            'customerInvitationGroups' => $customerInvitationGroups,
            'passwordConfirmationFresh' => $passwordConfirmationFresh,
            'mfaStepUpFresh' => $mfaStepUpFresh,
            'hasTotp' => $hasTotp,
            'hasPasskeys' => $hasPasskeys,
            'canManageMembershipWrites' => $hasManageableTenants
                && $passwordConfirmationFresh
                && $mfaStepUpFresh
                && ($hasTotp || $hasPasskeys),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
