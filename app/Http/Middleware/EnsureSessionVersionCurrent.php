<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSessionVersionCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $sessionVersion = $request->session()->get('auth_session_version');
        $storedSessionVersion = User::query()->whereKey($user->getKey())->value('session_version');

        if (! is_int($sessionVersion) || ! is_numeric($storedSessionVersion) || $sessionVersion !== (int) $storedSessionVersion) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your session is no longer valid. Sign in again.']);
        }

        return $next($request);
    }
}
