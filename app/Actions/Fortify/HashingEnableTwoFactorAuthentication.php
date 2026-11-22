<?php

declare(strict_types=1);

namespace App\\Actions\\Fortify;

use App\\Models\\User;
use App\\Services\\Identity\\MfaRecoveryCodeVault;
use Laravel\\Fortify\\Actions\\EnableTwoFactorAuthentication;
use Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider;
use Laravel\\Fortify\\Fortify;
use RuntimeException;

final class HashingEnableTwoFactorAuthentication extends EnableTwoFactorAuthentication
{
    public function __construct(
        TwoFactorAuthenticationProvider $provider,
        private readonly MfaRecoveryCodeVault $vault,
    ) {
        parent::__construct($provider);
    }

    public function __invoke($user, $force = false): void
    {
        parent::__invoke($user, $force);

        if (! $user instanceof User || ! is_string($user->two_factor_recovery_codes)) {
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

        $this->vault->replaceWithCodes($user, $codes);
    }
}
