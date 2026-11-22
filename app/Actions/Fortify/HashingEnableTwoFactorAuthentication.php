<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Services\Identity\MfaRecoveryCodeVault;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

final class HashingEnableTwoFactorAuthentication extends EnableTwoFactorAuthentication
{
    public function __construct(
        TwoFactorAuthenticationProvider $provider,
        private readonly MfaRecoveryCodeVault $vault,
        private readonly Request $request,
    ) {
        parent::__construct($provider);
    }

    public function __invoke($user, $force = false): void
    {
        if (! empty($user->two_factor_secret) && $force !== true) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $secretLength = (int) config('fortify-options.two-factor-authentication.secret-length', 16);
            $recoveryCodes = Collection::times(8, static fn (int $_index): string => RecoveryCode::generate())->all();

            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($this->provider->generateSecretKey($secretLength)),
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
            ])->save();

            $hashedCodes = $this->vault->replaceWithCodes($user, $recoveryCodes);
            $this->request->session()->flash('mfa.recovery_codes_once', $hashedCodes);

            TwoFactorAuthenticationEnabled::dispatch($user);
        }, 3);
    }
}
