<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class MfaEnrollmentController
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('security.mfa-enrollment', [
            'user' => $user,
            'passkeys' => $user->passkeys()->latest()->get(),
            'totpEnabled' => $user->hasEnabledTwoFactorAuthentication(),
        ]);
    }
}
