<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRecentMfaStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $steppedUpAt = $request->session()->get('auth.mfa_step_up_at');
        $method = $request->session()->get('auth.mfa_step_up_method');
        $timestamp = is_numeric($steppedUpAt) ? (int) $steppedUpAt : null;
        $ttl = max(1, (int) config('tlms.security.step_up_ttl_seconds', 300));
        $nowTimestamp = (int) now()->getTimestamp();

        $isFresh = $timestamp !== null
            && $timestamp <= $nowTimestamp
            && $timestamp >= ($nowTimestamp - $ttl)
            && is_string($method)
            && in_array($method, ['totp', 'passkey'], true);

        if (! $isFresh) {
            $request->session()->forget(['auth.mfa_step_up_at', 'auth.mfa_step_up_method']);

            $candidateRequestId = $request->headers->get('X-Request-ID');
            $requestId = is_string($candidateRequestId)
                && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $candidateRequestId) === 1
                    ? $candidateRequestId
                    : bin2hex(random_bytes(16));

            return response()->json([
                'type' => 'about:blank',
                'title' => 'Fresh MFA verification required',
                'status' => 423,
                'detail' => 'Complete a new authenticator or passkey challenge before this privileged action.',
                'code' => 'MFA_STEP_UP_REQUIRED',
                'request_id' => $requestId,
            ], 423, ['Content-Type' => 'application/problem+json']);
        }

        return $next($request);
    }
}
