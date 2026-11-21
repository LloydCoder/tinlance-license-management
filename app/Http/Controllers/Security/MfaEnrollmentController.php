<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class MfaEnrollmentController
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return view('security.mfa-enrollment', [
            'user' => $user,
            'passkeys' => $user->passkeys()->latest()->get(),
            'totpEnabled' => $user->hasEnabledTwoFactorAuthentication(),
        ]);
    }
}
