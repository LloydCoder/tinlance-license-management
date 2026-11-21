<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\Identity\SessionRevocationService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    public function __construct(
        private readonly SessionRevocationService $sessionRevocationService,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', Password::min(15)->max(1024), 'confirmed'],
        ])->validate();

        $user->forceFill([
            'password' => (string) $input['password'],
        ])->save();

        $this->sessionRevocationService->revokeAll($user);
    }
}
