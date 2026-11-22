<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformAccessAuditEvent;
use App\Models\User;
use App\Models\VendorAccount;
use App\Services\Authorization\PlatformAccessAuditChainWriter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PlatformAccessAuditDatabaseImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database trigger immutability is verified in MySQL/MariaDB integration jobs.');
        }
    }

    public function test_database_rejects_query_builder_updates_to_platform_access_audit_events(): void
    {
        $event = $this->createAuditEvent();

        $this->expectException(QueryException::class);

        DB::table('platform_access_audit_events')
            ->where('id', $event->getKey())
            ->update(['reason_code' => 'tampered']);
    }

    public function test_database_rejects_query_builder_deletes_from_platform_access_audit_events(): void
    {
        $event = $this->createAuditEvent();

        $this->expectException(QueryException::class);

        DB::table('platform_access_audit_events')
            ->where('id', $event->getKey())
            ->delete();
    }

    private function createAuditEvent(): PlatformAccessAuditEvent
    {
        $actor = User::query()->create([
            'name' => 'Audit Integrity Test',
            'email' => 'audit-integrity-'.str()->random(12).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'audit-integrity-'.str()->random(12),
            'legal_name' => 'Audit Integrity Vendor Ltd',
            'display_name' => 'Audit Integrity Vendor',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);

        return app(PlatformAccessAuditChainWriter::class)->append(
            (string) $actor->getKey(),
            'vendor.read',
            'vendor_account',
            (string) $vendor->getKey(),
            (string) $vendor->getKey(),
            'allowed',
            'platform_super_admin',
            bin2hex(random_bytes(16)),
        );
    }
}
