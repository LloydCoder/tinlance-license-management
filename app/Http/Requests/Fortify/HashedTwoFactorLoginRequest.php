<?php

declare(strict_types=1);

namespace App\Http\Requests\Fortify;

use App\Models\User;
use App\Services\Identity\MfaRecoveryCodeVault;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;

final class HashedTwoFactorLoginRequest extends TwoFactorLoginRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'regex:/\A[0-9]{6}\z/D'],
            'recovery_code' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function validRecoveryCode(): ?string
    {
        $code = $this->input('recovery_code');

        if (! is_string($code) || $code === '') {
            return null;
        }

        $user = $this->challengedUser();

        if (! $user instanceof User) {
            return null;
        }

        $replacement = app(MfaRecoveryCodeVault::class)->consumeAndReplace($user, $code);

        if (! is_string($replacement)) {
            return null;
        }

        $this->session()->flash('mfa.recovery_code_replacement_once', $replacement);
        $this->session()->forget('login.id');

        return $code;
    }
}
