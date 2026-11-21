<?php

declare(strict_types=1);

namespace App\\Services\\Identity;

use App\\Models\\PlatformInstallation;
use App\\Models\\Role;
use App\\Models\\User;
use App\\Models\\VendorAccount;
use App\\Models\\VendorMembership;
use Illuminate\\Auth\\Access\\AuthorizationException;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Validation\\ValidationException;

final class SuperAdminBootstrapService
{
    public function isAvailable(): bool
    {
        $token = config('tlms.bootstrap_token');

        if (! is_string($token) || strlen($token) < 43) {
            return false;
        }

        $installation = PlatformInstallation::query()
            ->where('installation_key', 'primary')
            ->first();

        return $installation !== null
            && $installation->bootstrapped_at === null
            && $installation->bootstrapped_user_id === null;
    }

    /**
     * @param array{name: string, email: string, password: string} $attributes
     */
    public function bootstrap(string $providedToken, array $attributes): User
    {
        $configuredToken = config('tlms.bootstrap_token');

        if (
            ! is_string($configuredToken)
            || strlen($configuredToken) < 43
            || ! hash_equals($configuredToken, $providedToken)
        ) {
            throw ValidationException::withMessages([
                'bootstrap_token' => 'The installation bootstrap credential is invalid.',
            ]);
        }

        return DB::transaction(function () use ($attributes): User {
            $installation = PlatformInstallation::query()
                ->where('installation_key', 'primary')
                ->lockForUpdate()
                ->first();

            if (
                $installation === null
                || $installation->bootstrapped_at !== null
                || $installation->bootstrapped_user_id !== null
            ) {
                abort(404);
            }

            $vendor = VendorAccount::query()->firstOrCreate(
                ['installation_key' => 'primary'],
                [
                    'legal_name' => (string) config('tlms.vendor_legal_name', 'Tinlance Limited'),
                    'display_name' => (string) config('tlms.vendor_display_name', 'Tinlance'),
                    'status' => 'active',
                    'default_currency' => 'USD',
                    'timezone' => 'UTC',
                ],
            );

            if ($vendor->status !== 'active') {
                abort(503, 'The primary vendor account is not active.');
            }

            $role = Role::query()
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin')
                ->where('is_system', true)
                ->first();

            if ($role === null) {
                abort(503, 'The authorization catalogue must be seeded before bootstrap.');
            }

            $existingSuperAdmin = VendorMembership::query()
                ->where('vendor_account_id', $vendor->getKey())
                ->whereHas('roles', static fn ($query) => $query
                    ->where('scope', 'vendor')
                    ->where('slug', 'super_admin'))
                ->exists();

            if ($existingSuperAdmin) {
                abort(404);
            }

            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => mb_strtolower(trim($attributes['email'])),
                'password' => $attributes['password'],
                'status' => 'active',
                'email_verified_at' => now(),
            ]);

            $membership = VendorMembership::query()->create([
                'vendor_account_id' => $vendor->getKey(),
                'user_id' => $user->getKey(),
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $membership->roles()->attach($role->getKey());

            $installation->forceFill([
                'bootstrapped_user_id' => $user->getKey(),
                'bootstrapped_at' => now(),
            ])->save();

            return $user;
        }, 3);
    }
}
