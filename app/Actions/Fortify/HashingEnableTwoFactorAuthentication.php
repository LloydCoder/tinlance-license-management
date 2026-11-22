<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

final class HashingEnableTwoFactorAuthentication extends EnableTwoFactorAuthentication
{
    public function __construct(TwoFactorAuthenticationProvider $provider)
    {
        parent::__construct($provider);
    }

    public function __invoke($user, $force = false): void
    {
        if (! empty($user->two_factor_secret) && $force !== true) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $secretLength = (int) config('fortify-options.two-factor-authentication.secret-length', 16);

            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->provider->generateSecretKey($secretLength)),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
                'two_factor_confirmed_at' => null,
            ])->save();

            DB::afterCommit(static fn () => TwoFactorAuthenticationEnabled::dispatch($user));
        }, 3);
    }
}
