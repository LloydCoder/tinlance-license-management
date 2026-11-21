<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

final class RecordSuccessfulLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $request = request();

        if ($request->hasSession()) {
            $request->session()->put('auth_session_version', (int) $event->user->session_version);
        }

        $event->user->forceFill(['last_login_at' => now()])->save();
    }
}
