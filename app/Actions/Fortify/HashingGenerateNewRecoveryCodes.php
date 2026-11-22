<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Identity\MfaRecoveryCodeVault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class HashingGenerateNewRecoveryCodes extends GenerateNewRecoveryCodes
{
    public function __construct(
        private readonly MfaRecoveryCodeVault $vault,
        private readonly Request $request,
    ) {}

    public function __invoke($user): void
    {
        if (! $user instanceof User) {
            throw new HttpException(401, 'An authenticated user is required.');
        }

        $stepUpAt = $this->request->session()->get('auth.mfa_step_up_at');
        $stepUpMethod = $this->request->session()->get('auth.mfa_step_up_method');
        $timestamp = is_numeric($stepUpAt) ? (int) $stepUpAt : null;
        $ttl = max(1, (int) config('tlms.security.step_up_ttl_seconds', 300));
        $now = (int) now()->getTimestamp();

        if (
            $timestamp === null
            || $timestamp > $now
            || $timestamp < ($now - $ttl)
            || ! is_string($stepUpMethod)
            || ! in_array($stepUpMethod, ['totp', 'passkey'], true)
        ) {
            throw new HttpException(423, 'A fresh MFA step-up is required to regenerate recovery codes.');
        }

        $key = 'mfa-recovery-regenerate:'.$user->getKey().':'.$this->request->ip();

        if (! RateLimiter::attempt($key, 3, static fn (): bool => true, 3600)) {
            throw new TooManyRequestsHttpException(3600, 'Recovery codes were regenerated too frequently.');
        }

        $codes = $this->vault->replaceAll($user);
        $this->request->session()->flash('mfa.recovery_codes_once', $codes);

        RecoveryCodesGenerated::dispatch($user);
    }
}
