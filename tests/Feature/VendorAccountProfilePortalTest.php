<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformInstallation;
use App\Models\PlatformAccessAuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorAccountProfileEvent;
use App\Models\VendorMembership;
use App\Services\Authorization\PlatformAccessAuditChainVerifier;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VendorAccountProfilePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_admin_can_view_and_update_only_the_primary_vendor_display_profile_with_payload_bound_audit(): void
    {
        [$admin, $vendor] = $this->createVendorAdmin();

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->get('/workspace/vendor-profile')
            ->assertOk()
            ->assertSee('Vendor profile')
            ->assertSee($vendor->display_name);

        $this->patch('/workspace/vendor-profile', [
            'display_name' => 'Tinlance Licensing Operations',
            'reason' => 'Update the customer-facing vendor display name.',
        ], ['X-Request-ID' => 'vendor-profile-update-0001'])
            ->assertRedirect('/workspace/vendor-profile')
            ->assertSessionHas('status');

        $vendor->refresh();
        $this->assertSame('Tinlance Licensing Operations', $vendor->display_name);
        $this->assertSame('Vendor primary Limited', $vendor->legal_name);
        $this->assertSame('active', $vendor->status);

        $event = VendorAccountProfileEvent::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('request_id', 'vendor-profile-update-0001')
            ->firstOrFail();
        $audit = PlatformAccessAuditEvent::query()
            ->where('request_id', 'vendor-profile-update-0001')
            ->firstOrFail();

        $this->assertSame(['display_name'], $event->changed_fields);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $event->change_digest);
        $this->assertSame(2, $audit->hash_version);
        $this->assertSame($event->getKey(), $audit->details_ref_id);
        $this->assertSame('vendor_account.profile_updated', $audit->action);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_vendor_profile_mutation_requires_recent_password_confirmation_and_fresh_mfa(): void
    {
        [$admin, $vendor] = $this->createVendorAdmin();

        $this->actingAs($admin, 'web')
            ->withSession([
                'auth_session_version' => (int) $admin->session_version,
                'auth.password_confirmed_at' => (int) now()->timestamp,
            ])
            ->patch('/workspace/vendor-profile', [
                'display_name' => 'Must Not Change',
                'reason' => 'Fresh MFA is mandatory for vendor profile changes.',
            ])
            ->assertStatus(423);

        $this->assertSame('Vendor primary', $vendor->refresh()->display_name);
        $this->assertDatabaseMissing('vendor_account_profile_events', [
            'vendor_account_id' => $vendor->getKey(),
        ]);
    }

    public function test_vendor_support_agent_cannot_manage_vendor_profile(): void
    {
        [$admin, $vendor] = $this->createVendorAdmin('support_agent');

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->get('/workspace/vendor-profile')
            ->assertForbidden();

        $this->patch('/workspace/vendor-profile', [
            'display_name' => 'Unauthorized Change',
            'reason' => 'Support role cannot mutate vendor profile.',
        ])->assertForbidden();

        $this->assertSame('Vendor primary', $vendor->refresh()->display_name);
    }

    public function test_bootstrap_super_admin_is_read_only_for_vendor_profile_mutations(): void
    {
        [$admin, $vendor] = $this->createVendorAdmin('super_admin');
        PlatformInstallation::query()->create([
            'installation_key' => 'primary',
            'bootstrapped_user_id' => $admin->getKey(),
            'bootstrapped_at' => now(),
        ]);

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->get('/workspace/vendor-profile')
            ->assertForbidden();

        $this->patch('/workspace/vendor-profile', [
            'display_name' => 'Bootstrap Mutation Attempt',
            'reason' => 'Bootstrap super admin remains read-only.',
        ])->assertForbidden();

        $this->assertSame('Vendor primary', $vendor->refresh()->display_name);
    }

    public function test_vendor_profile_update_rejects_system_and_commercial_fields(): void
    {
        [$admin, $vendor] = $this->createVendorAdmin();

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->patch('/workspace/vendor-profile', [
                'display_name' => 'Permitted Display',
                'legal_name' => 'Forged Legal Identity',
                'installation_key' => 'attacker-key',
                'status' => 'suspended',
                'default_currency' => 'USD',
                'timezone' => 'UTC',
                'reason' => 'Attempt to alter protected vendor attributes.',
            ])
            ->assertSessionHasErrors();

        $vendor->refresh();
        $this->assertSame('Vendor primary', $vendor->display_name);
        $this->assertSame('Vendor primary Limited', $vendor->legal_name);
        $this->assertSame('primary', $vendor->installation_key);
        $this->assertSame('active', $vendor->status);
        $this->assertSame('NGN', $vendor->default_currency);
        $this->assertSame('Africa/Lagos', $vendor->timezone);
    }

    public function test_vendor_profile_audit_verifier_detects_profile_event_tampering(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL/MariaDB triggers prevent profile-event mutation; SQLite exercises cross-record tamper detection.');
        }

        [$admin, $vendor] = $this->createVendorAdmin();

        $this->actingAs($admin, 'web')
            ->withSession($this->assuredSession($admin))
            ->patch('/workspace/vendor-profile', [
                'display_name' => 'Tamper Test',
                'reason' => 'Record vendor profile tamper evidence.',
            ], ['X-Request-ID' => 'vendor-profile-tamper-0001'])
            ->assertRedirect('/workspace/vendor-profile');

        $event = VendorAccountProfileEvent::query()
            ->where('request_id', 'vendor-profile-tamper-0001')
            ->firstOrFail();

        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());

        DB::table('vendor_account_profile_events')
            ->where('id', $event->getKey())
            ->update(['reason' => 'Tampered after append']);

        $this->assertFalse(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    /**
     * @return array{User, VendorAccount}
     */
    private function createVendorAdmin(string $roleSlug = 'vendor_admin'): array
    {
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'primary',
            'legal_name' => 'Vendor primary Limited',
            'display_name' => 'Vendor primary',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);

        $admin = User::query()->create([
            'name' => 'Vendor Profile Admin',
            'email' => 'vendor-profile-'.str()->random(12).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);

        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $admin->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', $roleSlug)->firstOrFail(),
        );

        $admin->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$admin->refresh(), $vendor];
    }

    /** @return array<string, int|string> */
    private function assuredSession(User $admin): array
    {
        return [
            'auth_session_version' => (int) $admin->session_version,
            'auth.password_confirmed_at' => (int) now()->timestamp,
            'auth.mfa_step_up_at' => (int) now()->timestamp,
            'auth.mfa_step_up_method' => 'totp',
        ];
    }
}
