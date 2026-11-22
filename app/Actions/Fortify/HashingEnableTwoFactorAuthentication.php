<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
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

    /** @param User $user */
    public function __invoke(mixed $user, mixed $force = false): void
    {
        if (! empty($user->two_factor_secret) && $force !== true) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->provider->generateSecretKey()),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
                'two_factor_confirmed_at' => null,
            ])->save();

            DB::afterCommit(static fn () => TwoFactorAuthenticationEnabled::dispatch($user));
        }, 3);
    }
}
