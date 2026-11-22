<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class MfaEnrollmentController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->view('security.mfa-enrollment', [
            'user' => $user,
            'passkeys' => $user->passkeys()->latest()->get(),
            'totpEnabled' => $user->hasEnabledTwoFactorAuthentication(),
        ])->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
    }
}
