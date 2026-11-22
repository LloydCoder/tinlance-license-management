<?php

declare(strict_types=1);

namespace Tests\\Feature;

use App\\Models\\CustomerMembership;
use App\\Models\\CustomerOrganization;
use App\\Models\\Role;
use App\\Models\\User;
use App\\Models\\VendorAccount;
use App\\Models\\VendorMembership;
use App\\Services\\Authorization\\MembershipDirectoryQuery;
use Database\\Seeders\\AuthorizationCatalogSeeder;
use Illuminate\\Auth\\Access\\AuthorizationException;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

final class MembershipDirectoryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_directory_lists_only_active_members_in_the_authorized_vendor(): void
    {
        [$actor, $vendor] = $this->vendorManager('directory-vendor-one');
        $otherVendor = $this->createVendor('directory-vendor-two');
        $active = $this->createVendorMember($vendor, 'active-member', 'active', 'support_agent');
        $invited = $this->createVendorMember($vendor, 'invited-member', 'invited', 'support_agent');
        $revoked = $this->createVendorMember($vendor, 'revoked-member', 'revoked', 'support_agent');
        $inactive = $this->createVendorMember($vendor, 'inactive-user', 'active', 'support_agent', 'suspended');
        $otherTenant = $this->createVendorMember($otherVendor, 'other-tenant-member', 'active', 'support_agent');

        $paginator = app(MembershipDirectoryQuery::class)->vendorMembers($actor, $vendor);
        $ids = collect($paginator->items())->pluck('id')->all();

        $this->assertSame(2, $paginator->total());
        $this->assertContains($actor->vendorMemberships()->where('vendor_account_id', $vendor->getKey())->value('id'), $ids);
        $this->assertContains($active->getKey(), $ids);
        $this->assertNotContains($invited->getKey(), $ids);
        $this->assertNotContains($revoked->getKey(), $ids);
        $this->assertNotContains($inactive->getKey(), $ids);
        $this->assertNotContains($otherTenant->getKey(), $ids);
        $this->assertSame('support_agent', $paginator->getCollection()->firstWhere('id', $active->getKey())->roles->sole()->slug);
        $this->assertSame(25, $paginator->perPage());
        $this->assertSame('vendor_members_'.$vendor->getKey().'_page', $paginator->getPageName());
        $this->assertArrayNotHasKey('token_hash', $paginator->items()[0]->toArray());
    }

    public function test_customer_directory_is_scoped_to_active_members_in_the_authorized_organization(): void
    {
        [$actor, $organization] = $this->customerOwner('directory-customer-one');
        $otherVendor = $this->createVendor('directory-customer-other-vendor');
        $otherOrganization = $this->createOrganization($otherVendor, 'directory-customer-two');
        $active = $this->createCustomerMember($organization, 'active-customer-member', 'active', 'customer_member');
        $invited = $this->createCustomerMember($organization, 'invited-customer-member', 'invited', 'customer_member');
        $revoked = $this->createCustomerMember($organization, 'revoked-customer-member', 'revoked', 'customer_member');
        $inactive = $this->createCustomerMember($organization, 'inactive-customer-user', 'active', 'customer_member', 'suspended');
        $otherTenant = $this->createCustomerMember($otherOrganization, 'other-customer-member', 'active', 'customer_member');

        $paginator = app(MembershipDirectoryQuery::class)->customerMembers($actor, $organization);
        $ids = collect($paginator->items())->pluck('id')->all();

        $this->assertSame(2, $paginator->total());
        $this->assertContains($actor->customerMemberships()->where('customer_organization_id', $organization->getKey())->value('id'), $ids);
        $this->assertContains($active->getKey(), $ids);
        $this->assertNotContains($invited->getKey(), $ids);
        $this->assertNotContains($revoked->getKey(), $ids);
        $this->assertNotContains($inactive->getKey(), $ids);
        $this->assertNotContains($otherTenant->getKey(), $ids);
        $this->assertSame('customer_member', $paginator->getCollection()->firstWhere('id', $active->getKey())->roles->sole()->slug);
        $this->assertSame('customer_members_'.$organization->getKey().'_page', $paginator->getPageName());
        $this->assertArrayNotHasKey('token_hash', $paginator->items()[0]->toArray());
    }

    public function test_directory_queries_deny_actors_without_tenant_membership_management_permission(): void
    {
        [$manager, $vendor] = $this->vendorManager('directory-deny-vendor');
        $supportAgent = $this->createUser('directory-support-agent');
        $this->createVendorMember($vendor, 'directory-support-agent-membership', 'active', 'support_agent', 'active', $supportAgent);

        try {
            app(MembershipDirectoryQuery::class)->vendorMembers($supportAgent, $vendor);
            $this->fail('A support agent without admins.manage must not list the vendor membership directory.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        [$owner, $organization] = $this->customerOwner('directory-deny-customer');
        $customerMember = $this->createUser('directory-customer-member');
        $this->createCustomerMember($organization, 'directory-customer-member-membership', 'active', 'customer_member', 'active', $customerMember);

        try {
            app(MembershipDirectoryQuery::class)->customerMembers($customerMember, $organization);
            $this->fail('A customer member without customer.members.manage must not list the organization directory.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_directory_queries_deny_suspended_parent_resources(): void
    {
        [$actor, $vendor] = $this->vendorManager('directory-suspended-vendor');
        VendorAccount::query()->whereKey($vendor->getKey())->update(['status' => 'suspended']);

        try {
            app(MembershipDirectoryQuery::class)->vendorMembers($actor, $vendor);
            $this->fail('A suspended vendor must not expose its membership directory.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        [$owner, $organization] = $this->customerOwner('directory-suspended-customer');
        CustomerOrganization::query()->whereKey($organization->getKey())->update(['status' => 'suspended']);

        try {
            app(MembershipDirectoryQuery::class)->customerMembers($owner, $organization);
            $this->fail('A suspended customer organization must not expose its membership directory.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_vendor_directory_is_paginated_at_twenty_five_members(): void
    {
        [$actor, $vendor] = $this->vendorManager('directory-pagination');
        for ($i = 0; $i < 25; $i++) {
            $this->createVendorMember($vendor, 'directory-page-'.$i, 'active', 'support_agent');
        }

        $paginator = app(MembershipDirectoryQuery::class)->vendorMembers($actor, $vendor);

        $this->assertSame(26, $paginator->total());
        $this->assertCount(25, $paginator->items());
        $this->assertTrue($paginator->hasMorePages());
    }

    private function vendorManager(string $label): array
    {
        $actor = $this->createUser($label);
        $vendor = $this->createVendor($label);
        $this->createVendorMember($vendor, $label.'-manager', 'active', 'vendor_admin', 'active', $actor);

        return [$actor, $vendor];
    }

    private function customerOwner(string $label): array
    {
        $actor = $this->createUser($label);
        $vendor = $this->createVendor($label);
        $organization = $this->createOrganization($vendor, $label);
        $this->createCustomerMember($organization, $label.'-owner', 'active', 'customer_owner', 'active', $actor);

        return [$actor, $organization];
    }

    private function createVendorMember(
        VendorAccount $vendor,
        string $label,
        string $membershipStatus,
        string $roleSlug,
        string $userStatus = 'active',
        ?User $user = null,
    ): VendorMembership {
        $user ??= $this->createUser($label);
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => $membershipStatus,
            'joined_at' => $membershipStatus === 'active' ? now() : null,
        ]);
        $membership->roles()->attach($this->role('vendor', $roleSlug));

        if ($userStatus !== 'active') {
            User::query()->whereKey($user->getKey())->update(['status' => $userStatus]);
        }

        return $membership;
    }

    private function createCustomerMember(
        CustomerOrganization $organization,
        string $label,
        string $membershipStatus,
        string $roleSlug,
        string $userStatus = 'active',
        ?User $user = null,
    ): CustomerMembership {
        $user ??= $this->createUser($label);
        $membership = CustomerMembership::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'status' => $membershipStatus,
            'joined_at' => $membershipStatus === 'active' ? now() : null,
        ]);
        $membership->roles()->attach($this->role('customer', $roleSlug));

        if ($userStatus !== 'active') {
            User::query()->whereKey($user->getKey())->update(['status' => $userStatus]);
        }

        return $membership;
    }

    private function createUser(string $label): User
    {
        return User::query()->create([
            'name' => 'Directory '.$label,
            'email' => str()->lower('directory-'.$label.'-'.str()->random(8).'@example.test'),
            'password' => str()->random(32),
            'status' => 'active',
        ]);
    }

    private function createVendor(string $label): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => 'vendor-'.str()->random(12),
            'legal_name' => 'Directory '.$label.' Limited',
            'display_name' => 'Directory '.$label,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }

    private function createOrganization(VendorAccount $vendor, string $label): CustomerOrganization
    {
        return CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'directory-'.str()->random(12),
            'legal_name' => 'Directory '.$label.' Limited',
            'display_name' => 'Directory '.$label,
            'status' => 'active',
        ]);
    }

    private function role(string $scope, string $slug): Role
    {
        return Role::query()->where('scope', $scope)->where('slug', $slug)->firstOrFail();
    }
}
