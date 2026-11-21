<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\User;
use App\Services\Identity\MfaStepUpAuditService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Illuminate\Validation\ValidationException;

final class MfaStepUpController
{
    public function store(
        Request $request,
        TwoFactorAuthenticationProvider $provider,
        MfaStepUpAuditService $audit,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $user->hasEnabledTwoFactorAuthentication() || ! is_string($user->two_factor_secret)) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'TOTP factor is not enrolled',
                'status' => 403,
                'detail' => 'Use an enrolled passkey or enroll an authenticator before requesting TOTP step-up.',
                'code' => 'TOTP_NOT_ENROLLED',
                'request_id' => bin2hex(random_bytes(16)),
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/D'],
        ]);

        try {
            $secret = decrypt($user->two_factor_secret);
        } catch (DecryptException) {
            return response()->json([
                'type' => 'about:blank',
                'title' => 'TOTP factor is unavailable',
                'status' => 403,
                'detail' => 'The enrolled authenticator secret could not be decrypted.',
                'code' => 'TOTP_FACTOR_UNAVAILABLE',
                'request_id' => bin2hex(random_bytes(16)),
            ], 403, ['Content-Type' => 'application/problem+json']);
        }

        if (! $provider->verify($secret, $validated['code'])) {
            $audit->record($user, 'totp', 'failed', $request);

            throw ValidationException::withMessages([
                'code' => ['The authenticator code is invalid or has already been used.'],
            ]);
        }

        $timestamp = (int) now()->getTimestamp();
        $ttl = max(1, (int) config('tlms.security.step_up_ttl_seconds', 300));
        $request->session()->put('auth.mfa_step_up_at', $timestamp);
        $request->session()->put('auth.mfa_step_up_method', 'totp');

        $event = $audit->record($user, 'totp', 'succeeded', $request);

        return response()->json([
            'data' => [
                'method' => 'totp',
                'stepped_up_at' => $timestamp,
                'expires_at' => $timestamp + $ttl,
                'expires_in' => $ttl,
            ],
            'request_id' => $event->request_id,
        ]);
    }
}
