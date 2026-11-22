<?php

declare(strict_types=1);

namespace App\Http\Controllers\Identity;

use App\Models\CustomerOrganization;
use App\Models\User;
use App\Services\Authorization\CustomerOrganizationManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

final class CustomerOrganizationProfileController
{
    public function index(
        Request $request,
        CustomerOrganizationManagementService $profiles,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $organizations = $profiles->customerProfileOrganizations($actor);
        abort_if($organizations->isEmpty(), 403);

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

        return view('workspace.customer-profiles.index', [
            'organizations' => $organizations,
            'passwordConfirmationFresh' => $passwordConfirmationFresh,
            'mfaStepUpFresh' => $mfaStepUpFresh,
            'hasTotp' => $actor->hasEnabledTwoFactorAuthentication(),
            'hasPasskeys' => $actor->hasPasskeysEnabled(),
            'canManageWrites' => $passwordConfirmationFresh
                && $mfaStepUpFresh
                && ($actor->hasEnabledTwoFactorAuthentication() || $actor->hasPasskeysEnabled()),
        ]);
    }

    public function update(
        Request $request,
        CustomerOrganization $organization,
        CustomerOrganizationManagementService $profiles,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('update', $organization);

        $validated = $request->validate([
            'legal_name' => ['prohibited'],
            'external_reference' => ['prohibited'],
            'vendor_account_id' => ['prohibited'],
            'status' => ['prohibited'],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'billing_email' => ['nullable', 'email:rfc', 'max:254'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $profiles->updateCustomerOwnedProfile(
            $actor,
            $organization,
            [
                'display_name' => $validated['display_name'],
                'billing_email' => $validated['billing_email'] ?? null,
            ],
            $validated['reason'],
            (string) $request->headers->get('X-Request-ID', ''),
        );

        return redirect()
            ->route('workspace.customer-profiles.index')
            ->with('status', 'Customer organization profile updated.');
    }
}
