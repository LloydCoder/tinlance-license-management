<?php

declare(strict_types=1);

namespace App\\Actions\\Fortify;

use App\\Models\\User;
use App\\Services\\Identity\\MfaRecoveryCodeVault;
use Illuminate\\Http\\Request;
use Laravel\\Fortify\\Actions\\EnableTwoFactorAuthentication;
use Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider;
use Laravel\\Fortify\\Fortify;
use RuntimeException;

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
        $shouldGenerate = $user instanceof User
            && (empty($user->two_factor_secret) || $force === true);

        parent::__invoke($user, $force);

        if (! $shouldGenerate || ! $user instanceof User || ! is_string($user->two_factor_recovery_codes)) {
            return;
        }

        $codes = json_decode(
            Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($codes) || ! array_is_list($codes) || count($codes) !== 8) {
            throw new RuntimeException('Fortify did not generate a complete recovery-code set.');
        }

        $hashedCodes = $this->vault->replaceWithCodes($user, $codes);
        $this->request->session()->flash('mfa.recovery_codes_once', $hashedCodes);
    }
}
