<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Security;

use App\Services\Identity\SessionRevocationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class SessionController
{
    public function index(Request $request): View
    {
        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return view('admin.security.sessions', [
            'sessions' => $sessions,
            'currentSessionId' => $request->session()->getId(),
        ]);
    }

    public function revokeAll(Request $request, SessionRevocationService $revocationService): RedirectResponse
    {
        $revocationService->revokeAll($request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'All active sessions have been revoked. Sign in again with your MFA factor.');
    }
}
