<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $this->withServerVariables(['HTTPS' => 'off'])
            ->get('/setup/super-admin')
            ->assertStatus(308)
            ->assertRedirect('https://localhost/setup/super-admin');

        $this->withServerVariables(['HTTPS' => 'on'])
            ->get('/setup/super-admin')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');
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

        $this->assertSessionHas('_old_input', static function (array $oldInput): bool {
            return ! array_key_exists('bootstrap_token', $oldInput)
                && ! array_key_exists('password', $oldInput)
                && ! array_key_exists('password_confirmation', $oldInput);
        });
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
        $this->assertNotNull($vendor->legal_name);
        $this->assertDatabaseHas('platform_installations', [
            'installation_key' => 'primary',
            'bootstrapped_user_id' => $user->getKey(),
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
