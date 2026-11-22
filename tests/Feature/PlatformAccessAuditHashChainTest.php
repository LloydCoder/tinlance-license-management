<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformAccessAuditEvent;
use App\Models\PlatformInstallation;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\PlatformAccessAuditChainHasher;
use App\Services\Authorization\PlatformAccessAuditChainVerifier;
use App\Services\Authorization\PlatformAccessAuditChainWriter;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class PlatformAccessAuditHashChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_platform_access_audit_events_form_a_contiguous_hash_chain(): void
    {
        $superAdmin = $this->bootstrapSuperAdmin();
        $firstVendor = $this->createVendor('hash-chain-first');
        $secondVendor = $this->createVendor('hash-chain-second');

        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $firstVendor));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('view', $secondVendor));

        $events = PlatformAccessAuditEvent::query()->orderBy('sequence')->get();

        $this->assertCount(2, $events);
        $first = $events->firstOrFail();
        $second = $events->skip(1)->firstOrFail();
        $this->assertSame(1, (int) $first->sequence);
        $this->assertNull($first->previous_hash);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $first->event_hash);
        $this->assertSame(2, (int) $second->sequence);
        $this->assertSame($first->event_hash, $second->previous_hash);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $second->event_hash);

        $head = DB::table('platform_access_audit_chain_heads')
            ->where('stream_key', 'platform_access')
            ->first();

        $this->assertNotNull($head);
        $this->assertSame(2, (int) $head->last_sequence);
        $this->assertSame($second->event_hash, $head->last_hash);

        $hasher = app(PlatformAccessAuditChainHasher::class);
        foreach ([$first, $second] as $event) {
            // Hash the raw persisted timestamp, not Eloquent's timezone-aware Carbon cast.
            $persistedCreatedAt = (string) DB::table('platform_access_audit_events')
                ->where('id', $event->getKey())
                ->value('created_at');

            $this->assertSame(
                $event->event_hash,
                $hasher->hashEvent(
                    (int) $event->sequence,
                    $event->previous_hash === null ? null : (string) $event->previous_hash,
                    (string) $event->id,
                    (string) $event->actor_user_id,
                    (string) $event->action,
                    (string) $event->resource_type,
                    (string) $event->resource_id,
                    (string) $event->tenant_vendor_account_id,
                    (string) $event->outcome,
                    (string) $event->reason_code,
                    (string) $event->request_id,
                    $persistedCreatedAt,
                ),
                'Persisted event hash must match its canonical database representation.',
            );
            $this->assertSame(1, (int) $event->hash_version);
        }

        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
        $this->assertSame(0, Artisan::call('tlms:audit:verify'));
        $this->assertStringContainsString('Platform access audit hash chain is valid.', Artisan::output());
    }

    public function test_verifier_detects_modified_audit_event_content(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL/MariaDB triggers prevent event mutation; SQLite exercises verifier tamper detection.');
        }

        $superAdmin = $this->bootstrapSuperAdmin();
        $vendor = $this->createVendor('tamper-detection');
        Gate::forUser($superAdmin)->allows('view', $vendor);
        $event = PlatformAccessAuditEvent::query()->firstOrFail();

        DB::table('platform_access_audit_events')
            ->where('id', $event->getKey())
            ->update(['reason_code' => 'resource_inactive']);

        $this->assertFalse(app(PlatformAccessAuditChainVerifier::class)->verify());
        $this->assertSame(1, Artisan::call('tlms:audit:verify'));
        $this->assertStringContainsString('Platform access audit hash chain verification failed.', Artisan::output());
    }

    public function test_verifier_detects_chain_head_replacement(): void
    {
        $superAdmin = $this->bootstrapSuperAdmin();
        $vendor = $this->createVendor('head-tamper-detection');
        Gate::forUser($superAdmin)->allows('view', $vendor);

        DB::table('platform_access_audit_chain_heads')
            ->where('stream_key', 'platform_access')
            ->update(['last_hash' => str_repeat('0', 64)]);

        $this->assertFalse(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_failed_audit_append_does_not_advance_the_chain_head(): void
    {
        $superAdmin = $this->bootstrapSuperAdmin();
        $vendor = $this->createVendor('failed-append');

        try {
            app(PlatformAccessAuditChainWriter::class)->append(
                (string) $superAdmin->getKey(),
                'vendor.delete',
                'vendor_account',
                (string) $vendor->getKey(),
                (string) $vendor->getKey(),
                'allowed',
                'platform_super_admin',
                bin2hex(random_bytes(16)),
            );
            $this->fail('An unsupported audit action must not be appended.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        $head = DB::table('platform_access_audit_chain_heads')
            ->where('stream_key', 'platform_access')
            ->first();

        $this->assertNotNull($head);
        $this->assertSame(0, (int) $head->last_sequence);
        $this->assertNull($head->last_hash);
        $this->assertDatabaseCount('platform_access_audit_events', 0);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    private function bootstrapSuperAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Audit Chain Super Admin',
            'email' => 'audit-chain-'.str()->random(12).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);
        $primaryVendor = $this->createVendor('primary');
        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $primaryVendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        PlatformInstallation::query()
            ->where('installation_key', 'primary')
            ->update([
                'bootstrapped_user_id' => $user->getKey(),
                'bootstrapped_at' => now(),
            ]);

        return $user;
    }

    private function createVendor(string $suffix): VendorAccount
    {
        return VendorAccount::query()->create([
            'installation_key' => $suffix,
            'legal_name' => 'Audit Chain '.$suffix.' Ltd',
            'display_name' => 'Audit Chain '.$suffix,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
    }
}
