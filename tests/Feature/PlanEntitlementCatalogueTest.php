<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlanEntitlementCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_product_manager_can_create_a_draft_plan_for_an_assigned_product(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');

        $product = $this->authenticated($manager)
            ->postJson('/api/v1/products', [
                'name' => 'Example Product',
                'slug' => 'example-product',
                'description' => 'Plan catalogue fixture.',
                'reason' => 'Create the plan catalogue fixture.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product.'/plans', [
                'code' => 'professional',
                'name' => 'Professional',
                'description' => 'Professional product entitlement tier.',
                'reason' => 'Introduce the professional plan.',
            ], ['X-Request-ID' => 'plan-create-000001'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'professional')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1);
    }


    public function test_draft_plan_version_can_be_updated_and_rejects_stale_plan_versions(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');

        $product = $this->authenticated($manager)
            ->postJson('/api/v1/products', [
                'name' => 'Editable Product',
                'slug' => 'editable-product',
                'description' => 'Draft version update fixture.',
                'reason' => 'Create the draft version update fixture.',
            ])
            ->assertCreated()
            ->json('data.id');

        $plan = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product.'/plans', [
                'code' => 'starter',
                'name' => 'Starter',
                'reason' => 'Create the starter plan.',
            ])
            ->assertCreated()
            ->json('data.id');

        $version = $this->authenticated($manager)
            ->postJson('/api/v1/plans/'.$plan.'/versions', [
                'expected_version' => 1,
                'billing_model' => 'subscription',
                'currency' => 'NGN',
                'price_minor' => 250000,
                'billing_interval' => 'month',
                'trial_days' => 7,
                'maintenance_days' => 30,
                'entitlements' => [
                    ['key' => 'seats', 'type' => 'integer', 'value' => 5],
                ],
                'reason' => 'Create the initial draft plan version.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($manager)
            ->patchJson('/api/v1/plans/'.$plan.'/versions/'.$version, [
                'expected_version' => 2,
                'billing_model' => 'subscription',
                'currency' => 'NGN',
                'price_minor' => 500000,
                'billing_interval' => 'year',
                'trial_days' => 14,
                'maintenance_days' => 365,
                'entitlements' => [
                    ['key' => 'seats', 'type' => 'integer', 'value' => 25],
                    ['key' => 'updates', 'type' => 'boolean', 'value' => true],
                ],
                'reason' => 'Revise the draft entitlement package.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.price_minor', 500000)
            ->assertJsonPath('data.entitlements.seats', 25)
            ->assertJsonPath('data.entitlements.updates', true);

        $this->authenticated($manager)
            ->patchJson('/api/v1/plans/'.$plan.'/versions/'.$version, [
                'expected_version' => 2,
                'billing_model' => 'subscription',
                'currency' => 'NGN',
                'price_minor' => 1,
                'billing_interval' => 'month',
                'trial_days' => 0,
                'maintenance_days' => 0,
                'entitlements' => [],
                'reason' => 'Attempt a stale draft update.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_VERSION_CONFLICT');
    }

    public function test_plan_version_entitlements_are_typed_and_snapshotted_when_published(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');

        $product = $this->authenticated($manager)
            ->postJson('/api/v1/products', [
                'name' => 'Snapshot Product',
                'slug' => 'snapshot-product',
                'description' => 'Entitlement snapshot fixture.',
                'reason' => 'Create the entitlement snapshot fixture.',
            ])
            ->assertCreated()
            ->json('data.id');

        $plan = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product.'/plans', [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Enterprise plan.',
                'reason' => 'Create the enterprise plan.',
            ])
            ->assertCreated()
            ->json('data.id');

        $version = $this->authenticated($manager)
            ->postJson('/api/v1/plans/'.$plan.'/versions', [
                'expected_version' => 1,
                'billing_model' => 'subscription',
                'currency' => 'NGN',
                'price_minor' => 12500000,
                'billing_interval' => 'year',
                'trial_days' => 14,
                'maintenance_days' => 365,
                'entitlements' => [
                    ['key' => 'seats', 'type' => 'integer', 'value' => 25],
                    ['key' => 'updates', 'type' => 'boolean', 'value' => true],
                    ['key' => 'support_level', 'type' => 'string', 'value' => 'priority'],
                ],
                'reason' => 'Define the first enterprise entitlement snapshot.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($manager)
            ->postJson('/api/v1/plans/'.$plan.'/versions/'.$version.'/publish', [
                'expected_version' => 2,
                'reason' => 'Publish the approved enterprise plan version.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.entitlements.seats', 25)
            ->assertJsonPath('data.entitlements.updates', true)
            ->assertJsonPath('data.entitlements.support_level', 'priority');

        $this->authenticated($manager)
            ->patchJson('/api/v1/plans/'.$plan.'/versions/'.$version, [
                'expected_version' => 2,
                'billing_model' => 'subscription',
                'currency' => 'NGN',
                'price_minor' => 100,
                'billing_interval' => 'year',
                'trial_days' => 0,
                'maintenance_days' => 0,
                'entitlements' => [],
                'reason' => 'Attempt to rewrite a published entitlement snapshot.',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: VendorAccount}
     */
    private function createActor(string $roleSlug): array
    {
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'primary',
            'legal_name' => 'Plan Catalogue Limited',
            'display_name' => 'Plan Catalogue',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);

        $user = User::query()->create([
            'name' => 'Plan Manager',
            'email' => 'plan-manager-'.str()->random(12).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);

        $membership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $membership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', $roleSlug)->firstOrFail(),
        );

        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user->refresh(), $vendor];
    }

    private function authenticated(User $user): static
    {
        return $this->actingAs($user, 'web')->withSession([
            'auth_session_version' => (int) $user->session_version,
            'auth.password_confirmed_at' => (int) now()->timestamp,
            'auth.mfa_step_up_at' => (int) now()->timestamp,
            'auth.mfa_step_up_method' => 'totp',
        ]);
    }
}
