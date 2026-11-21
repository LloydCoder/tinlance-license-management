<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

final class RecordSuccessfulLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $request = request();

        if ($request->hasSession()) {
            $sessionVersion = $user->getAttribute('session_version');

            if (is_numeric($sessionVersion)) {
                $request->session()->put('auth_session_version', (int) $sessionVersion);
            }
        }

        $user->forceFill(['last_login_at' => now()])->save();
    }
}
