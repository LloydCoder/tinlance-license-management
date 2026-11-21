<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMfaEnrolled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if (! $user->hasEnabledTwoFactorAuthentication() && ! $user->hasPasskeysEnabled()) {
            return redirect()->route('security.mfa.enroll');
        }

        return $next($request);
    }
}
