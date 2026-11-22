<?php

declare(strict_types=1);

namespace App\Http\Requests\Fortify;

use App\Models\User;
use App\Notifications\MfaRecoveryCodeActivityNotification;
use App\Services\Identity\MfaRecoveryCodeVault;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    public function hasValidCode(): bool
    {
        if ($this->eligibleChallengedUser() === null) {
            return false;
        }

        return parent::hasValidCode();
    }

    public function validRecoveryCode(): ?string
    {
        $code = $this->input('recovery_code');

        if (! is_string($code) || $code === '') {
            return null;
        }

        $user = $this->eligibleChallengedUser();

        if ($user === null) {
            return null;
        }

        $replacement = app(MfaRecoveryCodeVault::class)->consumeAndReplace($user, $code);

        if (! is_string($replacement)) {
            return null;
        }

        $this->session()->flash('mfa.recovery_code_replacement_once', $replacement);

        try {
            $user->notify(new MfaRecoveryCodeActivityNotification(
                'A saved MFA recovery code was used to sign in. A replacement code has been issued.',
            ));
        } catch (Throwable $exception) {
            Log::warning('TLMS could not deliver an MFA recovery-code usage notification.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
        $this->session()->forget('login.id');

        return $code;
    }

    private function eligibleChallengedUser(): ?User
    {
        /** @var User $user */
        $user = $this->challengedUser();

        if ($user->status !== 'active' || ! $user->hasEnabledTwoFactorAuthentication()) {
            return null;
        }

        return $user;
    }
}
