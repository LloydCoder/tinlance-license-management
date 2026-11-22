<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MfaEnrollmentController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $stepUpAt = $request->session()->get('auth.mfa_step_up_at');
        $stepUpMethod = $request->session()->get('auth.mfa_step_up_method');
        $timestamp = is_numeric($stepUpAt) ? (int) $stepUpAt : null;
        $ttl = max(1, (int) config('tlms.security.step_up_ttl_seconds', 300));
        $now = (int) now()->getTimestamp();
        $mfaStepUpFresh = $timestamp !== null
            && $timestamp <= $now
            && $timestamp >= ($now - $ttl)
            && is_string($stepUpMethod)
            && in_array($stepUpMethod, ['totp', 'passkey'], true);

        return response()->view('security.mfa-enrollment', [
            'user' => $user,
            'passkeys' => $user->passkeys()->latest()->get(),
            'totpEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            'hasPasskeys' => $user->hasPasskeysEnabled(),
            'mfaStepUpFresh' => $mfaStepUpFresh,
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
