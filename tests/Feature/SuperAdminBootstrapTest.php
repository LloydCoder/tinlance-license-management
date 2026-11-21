<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Models\VendorMembershipRoleEvent;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

final class SuperAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private const BOOTSTRAP_TOKEN = 'tlms-bootstrap-test-token-with-at-least-256-bits';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tlms.bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'tlms.vendor_legal_name' => 'Tinlance Limited',
            'tlms.vendor_display_name' => 'Tinlance',
        ]);
    }

    public function test_setup_routes_require_https_in_production_and_send_no_store_headers(): void
    {
        config(['app.env' => 'production']);

        $this->get('http://localhost/setup/super-admin')
            ->assertStatus(308)
            ->assertRedirect('https://localhost/setup/super-admin');

        $response = $this->get('https://localhost/setup/super-admin')
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function test_insecure_bootstrap_post_is_rejected_without_processing_the_credentials(): void
    {
        config(['app.env' => 'production']);

        $this->post('http://localhost/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'admin@example.test',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ])->assertStatus(426);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('vendor_accounts', 0);
    }

    public function test_bootstrap_page_is_not_available_without_a_configured_secret(): void
    {
        config(['tlms.bootstrap_token' => null]);

        $this->get('/setup/super-admin')->assertNotFound();
    }

    public function test_completion_page_is_not_available_before_bootstrap(): void
    {
        $this->get('/setup/super-admin/complete')->assertNotFound();
    }

    public function test_bootstrap_secret_is_never_flashed_back_to_the_browser(): void
    {
        $this->from('/setup/super-admin')->post('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'A',
            'email' => 'not-an-email',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ])->assertRedirect('/setup/super-admin');

        $oldInput = $this->app['session.store']->get('_old_input');

        $this->assertIsArray($oldInput);
        $this->assertArrayNotHasKey('bootstrap_token', $oldInput);
        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('password_confirmation', $oldInput);
    }

    public function test_invalid_bootstrap_secret_cannot_create_an_administrator(): void
    {
        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => 'not-the-configured-bootstrap-secret-256-bit-value',
            'name' => 'Initial Administrator',
            'email' => 'admin@example.test',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('vendor_accounts', 0);
    }

    public function test_bootstrap_accepts_long_passphrases_without_character_composition_rules(): void
    {
        $password = str_repeat('correct horse ', 4).'battery staple';

        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'passphrase@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated();

        $user = User::query()->where('email', 'passphrase@example.test')->firstOrFail();

        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNull($user->email_verified_at);
    }

    public function test_bootstrap_rejects_passwords_shorter_than_fifteen_characters(): void
    {
        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'short-password@example.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('vendor_accounts', 0);
    }

    public function test_bootstrap_rejects_passwords_over_1024_characters(): void
    {
        $password = str_repeat('a', 1025);

        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'oversized-password@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('vendor_accounts', 0);
    }

    public function test_bootstrap_fails_closed_when_the_authorization_catalogue_is_incomplete(): void
    {
        Permission::query()->create([
            'scope' => 'vendor',
            'name' => 'platform.settings.manage',
            'description' => 'Manage installation-wide platform settings.',
        ]);

        Role::query()->create([
            'scope' => 'vendor',
            'slug' => 'super_admin',
            'name' => 'Super Admin',
            'is_system' => true,
        ]);

        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'admin@example.test',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ])->assertStatus(503);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('vendor_accounts', 0);
    }

    public function test_bootstrap_rejects_cross_scope_permissions_on_the_super_admin_role(): void
    {
        app(AuthorizationCatalogSeeder::class)->run();

        $role = Role::query()
            ->where('scope', 'vendor')
            ->where('slug', 'super_admin')
            ->firstOrFail();
        $customerPermission = Permission::query()->create([
            'scope' => 'customer',
            'name' => 'customer.billing.read.extra',
            'description' => 'Read customer billing information.',
        ]);
        $role->permissions()->attach($customerPermission->getKey());

        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'admin@example.test',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ])->assertStatus(503);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('vendor_accounts', 0);
    }

    public function test_valid_one_time_bootstrap_creates_the_initial_super_admin(): void
    {
        $response = $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => ' ADMIN@EXAMPLE.TEST ',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('bootstrap_token');

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $vendor = VendorAccount::query()->where('installation_key', 'primary')->firstOrFail();
        $membership = VendorMembership::query()
            ->where('vendor_account_id', $vendor->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();
        $superAdmin = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();

        $this->assertSame('active', $user->status);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Correct-Horse-Battery-2026!', $user->password));
        $this->assertSame('active', $membership->status);
        $this->assertTrue($membership->roles()->whereKey($superAdmin->getKey())->exists());
        $this->assertSame('Tinlance Limited', $vendor->legal_name);
        $this->assertDatabaseHas('platform_installations', [
            'installation_key' => 'primary',
            'bootstrapped_user_id' => $user->getKey(),
        ]);
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $membership->getKey(),
            'role_id' => $superAdmin->getKey(),
            'actor_user_id' => null,
            'actor_type' => 'system',
            'action' => 'assigned',
            'reason' => 'Initial installation bootstrap',
        ]);
        $this->assertDatabaseHas('vendor_membership_role_events', [
            'vendor_membership_id' => $membership->getKey(),
            'role_id' => $superAdmin->getKey(),
            'actor_user_id' => null,
            'actor_type' => 'system',
            'action' => 'assigned',
            'reason' => 'Initial installation bootstrap',
        ]);
    }

    public function test_user_attributed_membership_events_require_a_human_actor(): void
    {
        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'admin@example.test',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ])->assertCreated();

        $membership = VendorMembership::query()->firstOrFail();
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();

        $this->expectException(LogicException::class);

        VendorMembershipRoleEvent::query()->create([
            'vendor_membership_id' => $membership->getKey(),
            'role_id' => $role->getKey(),
            'actor_user_id' => null,
            'actor_type' => 'user',
            'action' => 'assigned',
            'reason' => 'Invalid missing human actor',
            'created_at' => now(),
        ]);
    }

    public function test_system_attributed_membership_events_reject_a_human_actor_id(): void
    {
        $this->postJson('/setup/super-admin', [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'admin@example.test',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ])->assertCreated();

        $membership = VendorMembership::query()->firstOrFail();
        $role = Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail();
        $user = User::query()->firstOrFail();

        $this->expectException(LogicException::class);

        VendorMembershipRoleEvent::query()->create([
            'vendor_membership_id' => $membership->getKey(),
            'role_id' => $role->getKey(),
            'actor_user_id' => $user->getKey(),
            'actor_type' => 'system',
            'action' => 'assigned',
            'reason' => 'Invalid system actor with user id',
            'created_at' => now(),
        ]);
    }

    public function test_successful_bootstrap_is_irreversible_through_the_public_setup_endpoint(): void
    {
        $payload = [
            'bootstrap_token' => self::BOOTSTRAP_TOKEN,
            'name' => 'Initial Administrator',
            'email' => 'admin@example.test',
            'password' => 'Correct-Horse-Battery-2026!',
            'password_confirmation' => 'Correct-Horse-Battery-2026!',
        ];

        $this->postJson('/setup/super-admin', $payload)->assertCreated();

        $this->postJson('/setup/super-admin', [
            ...$payload,
            'email' => 'second-admin@example.test',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 1);
        $this->get('/setup/super-admin')->assertNotFound();
        $this->get('/setup/super-admin/complete')->assertOk();
        $this->assertGuest();
    }
}
