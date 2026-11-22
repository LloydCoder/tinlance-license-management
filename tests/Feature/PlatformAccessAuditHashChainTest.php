<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformInstallation;
use App\Models\PlatformAccessAuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(1, (int) $events[0]->sequence);
        $this->assertNull($events[0]->previous_hash);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $events[0]->event_hash);
        $this->assertSame(2, (int) $events[1]->sequence);
        $this->assertSame($events[0]->event_hash, $events[1]->previous_hash);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $events[1]->event_hash);

        $head = DB::table('platform_access_audit_chain_heads')
            ->where('stream_key', 'platform_access')
            ->first();

        $this->assertNotNull($head);
        $this->assertSame(2, (int) $head->last_sequence);
        $this->assertSame($events[1]->event_hash, $head->last_hash);
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
