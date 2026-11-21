<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class AuthorizationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [];

        foreach ($this->permissions() as $name => $description) {
            $scope = str_starts_with($name, 'customer.') ? 'customer' : 'vendor';

            $permissions[$name] = Permission::query()->updateOrCreate(
                ['scope' => $scope, 'name' => $name],
                ['description' => $description],
            );
        }

        $vendorPermissionNames = array_keys(array_filter(
            $permissions,
            static fn (Permission $permission): bool => $permission->scope === 'vendor',
        ));

        foreach ($this->roles($vendorPermissionNames) as $scope => $roles) {
            foreach ($roles as $slug => $definition) {
                $role = Role::query()->updateOrCreate(
                    ['scope' => $scope, 'slug' => $slug],
                    ['name' => $definition['name'], 'is_system' => true],
                );

                $permissionIds = array_map(
                    static fn (string $name): string => $permissions[$name]->getKey(),
                    $definition['permissions'],
                );

                $role->permissions()->sync($permissionIds);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function expectedVendorPermissionNames(): array
    {
        return array_values(array_filter(
            array_keys($this->permissions()),
            static fn (string $name): bool => ! str_starts_with($name, 'customer.'),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function permissions(): array
    {
        return [
            'vendor.account.read' => 'Read non-secret vendor account profile and operational status.',
            'platform.settings.manage' => 'Manage installation-wide platform settings.',
            'admins.manage' => 'Manage vendor administrator accounts and memberships.',
            'customers.manage' => 'Manage customer organizations and their account lifecycle.',
            'customers.support.read' => 'Read customer information for authorized support work.',
            'products.manage' => 'Manage vendor products and product configuration.',
            'plans.entitlements.manage' => 'Manage plans, versions and entitlement definitions.',
            'licences.issue' => 'Issue licences through approved issuance workflows.',
            'licences.read' => 'Read licence records and permitted diagnostics.',
            'licences.mutate' => 'Perform explicitly authorized licence lifecycle changes.',
            'payments.read' => 'Read payment and settlement information within assigned scope.',
            'payments.refund' => 'Initiate refunds subject to step-up and approval policy.',
            'ledger.adjust' => 'Create compensating ledger adjustments subject to approval policy.',
            'integrations.credentials.write' => 'Create, validate, rotate and disable provider credentials.',
            'releases.publish' => 'Publish authorized product releases subject to release policy.',
            'releases.nonproduction.publish' => 'Publish non-production releases only.',
            'signing.operational.rotate' => 'Request operational signing-key rotation through approval workflow.',
            'resellers.manage' => 'Manage reseller relationships and attribution within assigned scope.',
            'approvals.review' => 'Review and decide maker-checker approval requests within the vendor account.',
            'audit.read' => 'Read permitted audit evidence with sensitive values redacted.',
            'backups.restore' => 'Initiate controlled backup restoration with step-up and recorded approval.',
            'system.maintenance.manage' => 'Manage installation maintenance operations with step-up.',
            'customer.members.manage' => 'Manage members within the caller customer organization.',
            'customer.billing.read' => 'Read billing and invoice information for the caller organization.',
            'customer.licences.read' => 'Read licences belonging to the caller organization.',
            'customer.activations.manage' => 'Manage permitted activation operations for the caller organization.',
        ];
    }

    /**
     * @param  array<int, string>  $vendorPermissionNames
     * @return array<string, array<string, array{name: string, permissions: array<int, string>}>>
     */
    private function roles(array $vendorPermissionNames): array
    {
        return [
            'vendor' => [
                'super_admin' => [
                    'name' => 'Super Admin',
                    'permissions' => $vendorPermissionNames,
                ],
                'platform_admin' => [
                    'name' => 'Platform Admin',
                    'permissions' => ['vendor.account.read', 'platform.settings.manage', 'audit.read'],
                ],
                'finance_admin' => [
                    'name' => 'Finance Admin',
                    'permissions' => ['vendor.account.read', 'licences.issue', 'payments.read', 'payments.refund', 'ledger.adjust', 'audit.read'],
                ],
                'support_agent' => [
                    'name' => 'Support Agent',
                    'permissions' => ['vendor.account.read', 'customers.support.read', 'licences.read', 'audit.read'],
                ],
                'security_auditor' => [
                    'name' => 'Security Auditor',
                    'permissions' => ['vendor.account.read', 'audit.read', 'licences.read', 'payments.read'],
                ],
                'vendor_product_manager' => [
                    'name' => 'Vendor Product Manager',
                    'permissions' => ['vendor.account.read', 'products.manage', 'plans.entitlements.manage', 'licences.read', 'releases.publish'],
                ],
                'vendor_developer' => [
                    'name' => 'Vendor Developer',
                    'permissions' => ['vendor.account.read', 'licences.read', 'releases.nonproduction.publish'],
                ],
                'reseller_manager' => [
                    'name' => 'Reseller Manager',
                    'permissions' => ['vendor.account.read', 'resellers.manage', 'payments.read', 'audit.read'],
                ],
            ],
            'customer' => [
                'customer_owner' => [
                    'name' => 'Customer Organization Owner',
                    'permissions' => ['customer.members.manage', 'customer.billing.read', 'customer.licences.read', 'customer.activations.manage'],
                ],
                'customer_billing_admin' => [
                    'name' => 'Customer Billing Admin',
                    'permissions' => ['customer.billing.read'],
                ],
                'customer_technical_admin' => [
                    'name' => 'Customer Technical Admin',
                    'permissions' => ['customer.licences.read', 'customer.activations.manage'],
                ],
                'customer_member' => [
                    'name' => 'Customer Member',
                    'permissions' => ['customer.licences.read'],
                ],
            ],
        ];
    }
}
