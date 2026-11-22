<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Identity\MfaRecoveryCodeVault;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;

final class HashingConfirmTwoFactorAuthentication extends ConfirmTwoFactorAuthentication
{
    public function __construct(
        TwoFactorAuthenticationProvider $provider,
        private readonly MfaRecoveryCodeVault $vault,
        private readonly Request $request,
    ) {
        parent::__construct($provider);
    }

    public function __invoke($user, $code): void
    {
        if (
            empty($user->two_factor_secret)
            || empty($code)
            || ! $this->provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)
        ) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ])->errorBag('confirmTwoFactorAuthentication');
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        $codes = $this->vault->replaceAll($user);
        $this->request->session()->flash('mfa.recovery_codes_once', $codes);

        TwoFactorAuthenticationConfirmed::dispatch($user);
    }
}
