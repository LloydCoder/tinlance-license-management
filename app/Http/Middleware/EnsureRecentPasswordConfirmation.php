<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        $timeout = max(1, (int) config('auth.password_timeout', 10800));
        $confirmedAtTimestamp = is_numeric($confirmedAt) ? (int) $confirmedAt : null;
        $nowTimestamp = (int) now()->getTimestamp();
        $isFresh = $confirmedAtTimestamp !== null
            && $confirmedAtTimestamp >= ($nowTimestamp - $timeout);

        if (! $isFresh) {
            $candidateRequestId = $request->headers->get('X-Request-ID');
            $requestId = is_string($candidateRequestId)
                && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $candidateRequestId) === 1
                    ? $candidateRequestId
                    : bin2hex(random_bytes(16));

            return response()->json([
                'type' => 'about:blank',
                'title' => 'Password confirmation required',
                'status' => 423,
                'detail' => 'Confirm your password again before changing membership roles.',
                'code' => 'PASSWORD_CONFIRMATION_REQUIRED',
                'request_id' => $requestId,
            ], 423, ['Content-Type' => 'application/problem+json']);
        }

        return $next($request);
    }
}
