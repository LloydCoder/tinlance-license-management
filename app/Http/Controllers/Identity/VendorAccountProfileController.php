<?php

declare(strict_types=1);

namespace App\\Http\\Controllers\\Identity;

use App\\Models\\User;
use App\\Services\\Authorization\\VendorAccountProfileManagementService;
use Illuminate\\Http\\RedirectResponse;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Gate;
use Illuminate\\View\\View;

final class VendorAccountProfileController
{
    public function index(
        Request $request,
        VendorAccountProfileManagementService $profiles,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $vendor = $profiles->manageableVendor($actor);
        abort_unless($vendor !== null, 403);

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

        $hasTotp = $actor->hasEnabledTwoFactorAuthentication();
        $hasPasskeys = $actor->hasPasskeysEnabled();

        return view('workspace.vendor-profile.index', [
            'vendor' => $vendor,
            'passwordConfirmationFresh' => $passwordConfirmationFresh,
            'mfaStepUpFresh' => $mfaStepUpFresh,
            'hasTotp' => $hasTotp,
            'hasPasskeys' => $hasPasskeys,
            'canManageWrites' => $passwordConfirmationFresh
                && $mfaStepUpFresh
                && ($hasTotp || $hasPasskeys),
        ]);
    }

    public function update(
        Request $request,
        VendorAccountProfileManagementService $profiles,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $vendor = $profiles->manageableVendor($actor);
        abort_unless($vendor !== null, 403);
        Gate::forUser($actor)->authorize('update', $vendor);

        $validated = $request->validate([
            'legal_name' => ['prohibited'],
            'installation_key' => ['prohibited'],
            'status' => ['prohibited'],
            'default_currency' => ['prohibited'],
            'timezone' => ['prohibited'],
            'display_name' => ['required', 'string', 'min:2', 'max:160'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $profiles->updateDisplayName(
            $actor,
            $validated['display_name'],
            $validated['reason'],
            (string) $request->headers->get('X-Request-ID', ''),
        );

        return redirect()
            ->route('workspace.vendor-profile.index')
            ->with('status', 'Vendor profile updated.');
    }
}
