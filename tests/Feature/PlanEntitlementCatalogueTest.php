<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\PlatformAccessAuditChainVerifier;
use App\Services\Catalog\ProductAssignmentService;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_assigned_product_manager_can_list_and_read_product_plans(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');

        $product = $this->authenticated($manager)
            ->postJson('/api/v1/products', [
                'name' => 'Readable Product',
                'slug' => 'readable-product',
                'description' => 'Plan read fixture.',
                'reason' => 'Create the plan read fixture.',
            ])
            ->assertCreated()
            ->json('data.id');

        $plan = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product.'/plans', [
                'code' => 'readable',
                'name' => 'Readable',
                'reason' => 'Create the readable plan.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($manager)
            ->getJson('/api/v1/products/'.$product.'/plans')
            ->assertOk()
            ->assertJsonPath('data.0.id', $plan)
            ->assertJsonPath('data.0.code', 'readable');

        $this->authenticated($manager)
            ->getJson('/api/v1/plans/'.$plan)
            ->assertOk()
            ->assertJsonPath('data.id', $plan)
            ->assertJsonPath('data.name', 'Readable');

        $this->authenticated($manager)
            ->getJson('/api/v1/plans/'.$plan.'/versions')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
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

        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());

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

        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
        $this->assertDatabaseHas('platform_access_audit_events', [
            'action' => 'plan.created',
            'resource_type' => 'plan',
        ]);
        $this->assertDatabaseHas('platform_access_audit_events', [
            'action' => 'plan_version.published',
            'resource_type' => 'plan_version',
        ]);

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


    public function test_product_readers_only_see_published_plan_versions(): void
    {
        [$manager, $vendor] = $this->createActor('vendor_super_admin');

        $productId = $this->authenticated($manager)
            ->postJson('/api/v1/products', [
                'name' => 'Reader Visibility Product',
                'slug' => 'reader-visibility-product',
                'description' => 'Draft and published plan visibility fixture.',
                'reason' => 'Create the reader visibility product.',
            ])
            ->assertCreated()
            ->json('data.id');

        $planId = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$productId.'/plans', [
                'code' => 'reader-plan',
                'name' => 'Reader Plan',
                'reason' => 'Create a plan with a private draft version.',
            ])
            ->assertCreated()
            ->json('data.id');

        $draftVersionId = $this->authenticated($manager)
            ->postJson('/api/v1/plans/'.$planId.'/versions', [
                'expected_version' => 1,
                'billing_model' => 'subscription',
                'currency' => 'NGN',
                'price_minor' => 100000,
                'billing_interval' => 'month',
                'trial_days' => 0,
                'maintenance_days' => 30,
                'entitlements' => [
                    ['key' => 'seats', 'type' => 'integer', 'value' => 5],
                ],
                'reason' => 'Create the private draft plan version.',
            ])
            ->assertCreated()
            ->json('data.id');

        $reader = User::query()->create([
            'name' => 'Product Reader',
            'email' => 'product-reader-'.str()->random(12).'@example.test',
            'password' => str()->random(40),
            'status' => 'active',
        ]);
        $readerMembership = VendorMembership::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'user_id' => $reader->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $readerMembership->roles()->attach(
            Role::query()->where('scope', 'vendor')->where('slug', 'vendor_product_reader')->firstOrFail(),
        );
        $reader->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        app(ProductAssignmentService::class)->grant(
            $manager,
            Product::query()->findOrFail($productId),
            (string) $readerMembership->getKey(),
            'Grant reader access to this product.',
            'plan-reader-visibility',
        );

        $this->authenticated($reader)
            ->getJson('/api/v1/products/'.$productId.'/plans')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->authenticated($reader)
            ->getJson('/api/v1/plans/'.$planId)
            ->assertNotFound();

        $this->authenticated($reader)
            ->getJson('/api/v1/plans/'.$planId.'/versions')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->authenticated($manager)
            ->postJson('/api/v1/plans/'.$planId.'/versions/'.$draftVersionId.'/publish', [
                'expected_version' => 2,
                'reason' => 'Publish the first plan version for product readers.',
            ])
            ->assertOk();

        $this->authenticated($manager)
            ->postJson('/api/v1/plans/'.$planId.'/versions', [
                'expected_version' => 3,
                'billing_model' => 'subscription',
                'currency' => 'NGN',
                'price_minor' => 200000,
                'billing_interval' => 'year',
                'trial_days' => 0,
                'maintenance_days' => 365,
                'entitlements' => [
                    ['key' => 'seats', 'type' => 'integer', 'value' => 20],
                ],
                'reason' => 'Create the next private draft plan version.',
            ])
            ->assertCreated();

        $this->authenticated($reader)
            ->getJson('/api/v1/products/'.$productId.'/plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->authenticated($reader)
            ->getJson('/api/v1/plans/'.$planId.'/versions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'published');

        $this->authenticated($manager)
            ->getJson('/api/v1/plans/'.$planId.'/versions')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_database_rejects_commercial_term_mutation_during_publication(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database trigger verification runs on MySQL and MariaDB integration jobs.');
        }

        [$manager] = $this->createActor('vendor_product_manager');

        $product = $this->authenticated($manager)
            ->postJson('/api/v1/products', [
                'name' => 'Immutable Product',
                'slug' => 'immutable-product',
                'description' => 'Database publication invariant fixture.',
                'reason' => 'Create the immutable publication fixture.',
            ])
            ->assertCreated()
            ->json('data.id');

        $plan = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product.'/plans', [
                'code' => 'immutable',
                'name' => 'Immutable',
                'reason' => 'Create the immutable plan.',
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
                'trial_days' => 0,
                'maintenance_days' => 30,
                'entitlements' => [
                    ['key' => 'seats', 'type' => 'integer', 'value' => 5],
                ],
                'reason' => 'Create the immutable draft version.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->expectException(QueryException::class);

        DB::table('plan_versions')
            ->where('id', $version)
            ->update([
                'status' => 'published',
                'price_minor' => 999999,
                'published_at' => now(),
                'snapshot_sha256' => str_repeat('a', 64),
            ]);
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
