<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Notifications\MfaRecoveryCodeActivityNotification;
use App\Services\Identity\MfaRecoveryCodeVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

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
        if ($user->status !== 'active') {
            throw new HttpException(403, 'Inactive accounts cannot confirm authenticator enrollment.');
        }

        $key = 'mfa-enrollment-confirm:'.$user->getKey().':'.$this->request->ip();

        if (! RateLimiter::attempt($key, 5, static fn (): bool => true, 60)) {
            throw new TooManyRequestsHttpException(60, 'Too many authenticator confirmation attempts.');
        }

        if (
            empty($user->two_factor_secret)
            || empty($code)
            || ! $this->provider->verify(Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret), $code)
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

        try {
            $user->notify(new MfaRecoveryCodeActivityNotification(
                'Your MFA recovery codes were issued after authenticator confirmation. They are displayed once in your authenticated TLMS session.',
            ));
        } catch (Throwable $exception) {
            Log::warning('TLMS could not deliver an MFA recovery-code issuance notification.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
