<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SessionRevocationService
{
    public function revokeAll(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->forceFill([
                'session_version' => ((int) $lockedUser->session_version) + 1,
                'remember_token' => Str::random(60),
            ])->save();

            return DB::table('sessions')
                ->where('user_id', $lockedUser->getKey())
                ->delete();
        });
    }
}
