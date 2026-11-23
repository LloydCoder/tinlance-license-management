<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformAccessAuditEvent;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\PlatformAccessAuditChainVerifier;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class ProductActivationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_product_activates_atomically_when_a_published_version_is_promoted(): void
    {
        $actor = $this->createActor();
        $productId = $this->authenticated($actor)
            ->postJson('/api/v1/products', [
                'name' => 'Lifecycle Product',
                'slug' => 'lifecycle-product',
                'description' => 'Product activation lifecycle fixture.',
                'reason' => 'Create the lifecycle product.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $versionId = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/versions', [
                'version' => '1.0.0',
                'release_notes' => 'Initial production release.',
                'artifact_sha256' => hash('sha256', 'fixture-artifact'),
                'artifact_size_bytes' => 128,
                'reason' => 'Create the first product version.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/versions/'.$versionId.'/publish', [
                'reason' => 'Publish the first product version.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $channelId = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/release-channels', [
                'slug' => 'stable',
                'name' => 'Stable',
                'reason' => 'Create the stable release channel.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'draft']);

        $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/release-channels/'.$channelId.'/promote', [
                'product_version_id' => $versionId,
                'expected_version' => 1,
                'reason' => 'Promote the first stable production release.',
            ], ['X-Request-ID' => 'product-first-release-activation'])
            ->assertOk()
            ->assertJsonPath('data.current_product_version_id', $versionId);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'status' => 'active',
            'version' => 2,
        ]);
        $this->assertDatabaseHas('product_events', [
            'product_id' => $productId,
            'event_type' => 'activated',
            'changed_fields' => json_encode(['status']),
        ]);
        $this->assertDatabaseHas('platform_access_audit_events', [
            'action' => 'product.activated',
            'resource_type' => 'product',
            'resource_id' => $productId,
        ]);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_product_cannot_be_activated_without_a_promoted_published_release(): void
    {
        $actor = $this->createActor();
        $productId = $this->authenticated($actor)
            ->postJson('/api/v1/products', [
                'name' => 'Unreleased Product',
                'slug' => 'unreleased-product',
                'description' => 'Activation guard fixture.',
                'reason' => 'Create the unreleased product.',
            ])
            ->assertCreated()
            ->json('data.id');

        $product = Product::query()->findOrFail($productId);

        try {
            $product->forceFill([
                'status' => 'active',
                'version' => 2,
                'updated_by_user_id' => $actor->getKey(),
            ])->save();

            $this->fail('A product without a promoted published release must not activate.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'draft', 'version' => 1]);
    }

    private function createActor(): User
    {
        $vendor = VendorAccount::query()->create([
            'installation_key' => 'primary',
            'legal_name' => 'Product Lifecycle Limited',
            'display_name' => 'Product Lifecycle',
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);

        $user = User::query()->create([
            'name' => 'Product Lifecycle Admin',
            'email' => 'product-lifecycle-'.str()->random(12).'@example.test',
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
            Role::query()->where('scope', 'vendor')->where('slug', 'super_admin')->firstOrFail(),
        );

        $user->forceFill([
            'email_verified_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode([], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
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
