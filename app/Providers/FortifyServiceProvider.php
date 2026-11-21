<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::authenticateUsing(static function (Request $request): ?User {
            $email = Str::lower(trim((string) $request->input(Fortify::username(), '')));
            $password = (string) $request->input('password', '');

            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User || $user->status !== 'active' || $password === '') {
                return null;
            }

            return Hash::check($password, $user->password) ? $user : null;
        });

        Passkeys::authorizeLoginUsing(static function (Request $request, PasskeyUser $user, Passkey $passkey): bool {
            return $user instanceof User
                && $user->status === 'active'
                && $user->hasVerifiedEmail();
        });

        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView('auth.login');
        Fortify::twoFactorChallengeView('auth.two-factor-challenge');
        Fortify::verifyEmailView('auth.verify-email');
        Fortify::requestPasswordResetLinkView('auth.forgot-password');
        Fortify::resetPasswordView('auth.reset-password');
        Fortify::confirmPasswordView('auth.confirm-password');

        RateLimiter::for('login', static function (Request $request): array {
            $email = Str::lower(trim((string) $request->input(Fortify::username(), '')));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$ip),
                Limit::perMinute(20)->by('login-ip:'.$ip),
            ];
        });

        RateLimiter::for('two-factor', static function (Request $request): Limit {
            $challengeId = (string) $request->session()->get('login.id', 'unknown');

            return Limit::perMinute(5)->by('two-factor:'.$challengeId.'|'.$request->ip());
        });

        RateLimiter::for('passkeys', static fn (Request $request): Limit => Limit::perMinute(6)->by('passkeys:'.$request->ip()));
    }
}
