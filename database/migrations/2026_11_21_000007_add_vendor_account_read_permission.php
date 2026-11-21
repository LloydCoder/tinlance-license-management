<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const VENDOR_ROLES = [
        'super_admin',
        'platform_admin',
        'finance_admin',
        'support_agent',
        'security_auditor',
        'vendor_product_manager',
        'vendor_developer',
        'reseller_manager',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $permission = DB::table('permissions')
                ->where('scope', 'vendor')
                ->where('name', 'vendor.account.read')
                ->first();

            $permissionId = $permission?->id;

            if (! is_string($permissionId)) {
                $permissionId = (string) Str::ulid();

                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'scope' => 'vendor',
                    'name' => 'vendor.account.read',
                    'description' => 'Read non-secret vendor account profile and operational status.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $roleIds = DB::table('roles')
                ->where('scope', 'vendor')
                ->where('is_system', true)
                ->whereIn('slug', self::VENDOR_ROLES)
                ->pluck('id');

            $assignments = [];

            foreach ($roleIds as $roleId) {
                $assignments[] = [
                    'role_id' => (string) $roleId,
                    'permission_id' => $permissionId,
                ];
            }

            if ($assignments !== []) {
                DB::table('role_permissions')->insertOrIgnore($assignments);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')
                ->where('scope', 'vendor')
                ->where('name', 'vendor.account.read')
                ->value('id');

            if (is_string($permissionId)) {
                DB::table('role_permissions')
                    ->where('permission_id', $permissionId)
                    ->delete();

                DB::table('permissions')
                    ->where('id', $permissionId)
                    ->delete();
            }
        });
    }
};
