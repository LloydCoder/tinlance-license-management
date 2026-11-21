<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Identity\MfaStepUpAuditService;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Events\PasskeyVerified;

final class RecordPasskeyStepUp
{
    public function handle(PasskeyVerified $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $guard = (string) config('fortify.guard', 'web');
        $authenticatedUser = Auth::guard($guard)->user();

        if (! $authenticatedUser instanceof User || ! $authenticatedUser->is($user)) {
            return;
        }

        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $request->session()->put('auth.mfa_step_up_at', now()->timestamp);
        $request->session()->put('auth.mfa_step_up_method', 'passkey');

        app(MfaStepUpAuditService::class)->record($user, 'passkey', 'succeeded', $request);
    }
}
