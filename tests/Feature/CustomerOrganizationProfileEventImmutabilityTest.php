<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerOrganization;
use App\Models\CustomerOrganizationProfileEvent;
use App\Models\User;
use App\Models\VendorAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CustomerOrganizationProfileEventImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database trigger immutability is verified in MySQL/MariaDB integration jobs.');
        }
    }

    public function test_database_rejects_query_builder_updates_to_customer_profile_events(): void
    {
        $event = $this->createProfileEvent();

        $this->expectException(QueryException::class);

        DB::table('customer_organization_profile_events')
            ->where('id', $event->getKey())
            ->update(['reason' => 'tampered']);
    }

    public function test_database_rejects_query_builder_deletes_from_customer_profile_events(): void
    {
        $event = $this->createProfileEvent();

        $this->expectException(QueryException::class);

        DB::table('customer_organization_profile_events')
            ->where('id', $event->getKey())
            ->delete();
    }

    private function createProfileEvent(): CustomerOrganizationProfileEvent
    {
        $actor = User::query()->create([
            'name' => 'Customer Profile Audit Test',
            'email' => 'customer-profile-audit-'.str()->random(12).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'customer-profile-audit-'.str()->random(12),
            'legal_name' => 'Customer Profile Audit Vendor Ltd',
            'display_name' => 'Customer Profile Audit Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
        $organization = CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'audit-'.str()->random(8),
            'legal_name' => 'Customer Profile Audit Limited',
            'display_name' => 'Customer Profile Audit',
            'status' => 'active',
            'billing_email' => 'billing@example.test',
        ]);

        return CustomerOrganizationProfileEvent::query()->create([
            'customer_organization_id' => $organization->getKey(),
            'actor_user_id' => $actor->getKey(),
            'event_type' => 'profile_updated',
            'reason' => 'Test append-only customer profile audit evidence.',
            'changed_fields' => ['display_name'],
            'request_id' => bin2hex(random_bytes(16)),
            'created_at' => now(),
        ]);
    }
}
