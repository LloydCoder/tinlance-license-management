<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Identity\MfaRecoveryCodeVault;
use Illuminate\Database\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class HashingConfirmTwoFactorAuthentication extends ConfirmTwoFactorAuthentication
{
    public function __construct(
        TwoFactorAuthenticationProvider $provider,
        private readonly MfaRecoveryCodeVault $vault,
        private readonly Request $request,
    ) {
        parent::__construct($provider);
    }

    /** @param User $user @param string $code */
    public function __invoke(mixed $user, mixed $code): void
    {
        $key = 'mfa-enrollment-confirm:'.$user->getKey().':'.$this->request->ip();

        if (! RateLimiter::attempt($key, 5, static fn (): bool => true, 60)) {
            throw new TooManyRequestsHttpException(60, 'Too many authenticator confirmation attempts.');
        }

        if (
            empty($user->two_factor_secret)
            || empty($code)
            || ! $this->provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)
        ) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ])->errorBag('confirmTwoFactorAuthentication');
        }

        $codes = DB::transaction(function () use ($user): array {
            $user->forceFill([
                'two_factor_confirmed_at' => now(),
            ])->save();

            $codes = $this->vault->replaceAll($user);
            DB::afterCommit(static fn () => TwoFactorAuthenticationConfirmed::dispatch($user));

            return $codes;
        }, 3);

        $this->request->session()->flash('mfa.recovery_codes_once', $codes);
        RateLimiter::clear($key);
    }
}
