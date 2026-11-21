<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\PlatformInstallation;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Support\Facades\DB;

final class SuperAdminBootstrapService
{
    public function isAvailable(): bool
    {
        $token = config('tlms.bootstrap_token');
        $appKey = config('app.key');

        if (
            ! is_string($token)
            || preg_match('/\\A[A-Za-z0-9_-]{43,128}\\z/', $token) !== 1
            || ! is_string($appKey)
            || $appKey === ''
            || config('session.secure') !== true
            || config('session.encrypt') !== true
            || (! in_array(config('app.env'), ['local', 'testing'], true) && config('app.debug') !== false)
        ) {
            return false;
        }

        $installation = PlatformInstallation::query()
            ->where('installation_key', 'primary')
            ->first();

        if (
            $installation === null
            || $installation->bootstrapped_at !== null
            || $installation->bootstrapped_user_id !== null
        ) {
            return false;
        }

        return ! VendorMembership::query()
            ->whereHas('roles', static fn ($query) => $query
                ->where('scope', 'vendor')
                ->where('slug', 'super_admin'))
            ->exists();
    }

    public function isBootstrapped(): bool
    {
        $installation = PlatformInstallation::query()
            ->where('installation_key', 'primary')
            ->firstOrFail();

        return $installation->bootstrapped_at !== null
            && $installation->bootstrapped_user_id !== null;
    }

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function bootstrap(string $providedToken, array $attributes): User
    {
        $configuredToken = config('tlms.bootstrap_token');

        if (
            ! $this->isAvailable()
            || ! is_string($configuredToken)
            || preg_match('/\\A[A-Za-z0-9_-]{43,128}\\z/', $configuredToken) !== 1
            || ! hash_equals($configuredToken, $providedToken)
        ) {
            abort(404);
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

            $legalName = trim((string) config('tlms.vendor_legal_name', 'Tinlance Limited'));
            $displayName = trim((string) config('tlms.vendor_display_name', 'Tinlance'));

            if ($legalName === '' || mb_strlen($legalName) > 255 || $displayName === '' || mb_strlen($displayName) > 160) {
                abort(503, 'The primary vendor identity is not configured correctly.');
            }

            $vendor = VendorAccount::query()->firstOrCreate(
                ['installation_key' => 'primary'],
                [
                    'legal_name' => $legalName,
                    'display_name' => $displayName,
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

            $authorizationCatalog = app(AuthorizationCatalogSeeder::class);

            if ($role === null && ! Role::query()->exists()) {
                $authorizationCatalog->run();

                $role = Role::query()
                    ->where('scope', 'vendor')
                    ->where('slug', 'super_admin')
                    ->where('is_system', true)
                    ->first();
            }

            if ($role === null) {
                abort(503, 'The authorization catalogue could not be initialized.');
            }

            $expectedPermissionNames = $authorizationCatalog->expectedVendorPermissionNames();
            $assignedPermissionNames = $role->permissions()
                ->orderBy('permissions.name')
                ->pluck('permissions.name')
                ->all();

            sort($expectedPermissionNames);
            sort($assignedPermissionNames);

            if ($assignedPermissionNames !== $expectedPermissionNames) {
                abort(503, 'The authorization catalogue is incomplete.');
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
