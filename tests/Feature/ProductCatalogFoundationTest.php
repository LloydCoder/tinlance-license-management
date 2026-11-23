<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PlatformAccessAuditEvent;
use App\Models\PlatformInstallation;
use App\Models\Product;
use App\Models\ProductAssignment;
use App\Models\ProductEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Authorization\PlatformAccessAuditChainVerifier;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

final class ProductCatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_vendor_product_manager_can_create_and_update_own_draft_product_with_payload_bound_audit(): void
    {
        [$manager, $vendor] = $this->createActor('vendor_product_manager');

        $created = $this->authenticated($manager)
            ->postJson('/api/v1/products', [
                'name' => 'ThreatFade Network Detection',
                'slug' => 'threatfade',
                'description' => 'Network threat detection and response.',
                'reason' => 'Register the first commercial product.',
            ], ['X-Request-ID' => 'product-create-000001'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', 'threatfade');

        $productId = $created->json('data.id');
        $product = Product::query()->whereKey($productId)->firstOrFail();
        $this->assertSame($vendor->getKey(), $product->vendor_account_id);
        $this->assertSame($manager->getKey(), $product->created_by_user_id);
        $this->assertSame(1, $product->version);

        $event = ProductEvent::query()
            ->where('product_id', $product->getKey())
            ->where('request_id', 'product-create-000001')
            ->firstOrFail();
        $audit = PlatformAccessAuditEvent::query()
            ->where('request_id', 'product-create-000001')
            ->firstOrFail();

        $this->assertSame('created', $event->event_type);
        $this->assertSame(2, $audit->hash_version);
        $this->assertSame($event->getKey(), $audit->details_ref_id);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $event->change_digest);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());

        $this->authenticated($manager)
            ->patchJson('/api/v1/products/'.$productId, [
                'expected_version' => 1,
                'name' => 'ThreatFade Network Threat Detection',
                'slug' => 'threatfade',
                'description' => 'Network threat detection, investigation and response.',
                'reason' => 'Clarify the product catalogue description.',
            ], ['X-Request-ID' => 'product-update-000001'])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.name', 'ThreatFade Network Threat Detection');

        $this->assertSame(2, $product->refresh()->version);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_product_manager_cannot_read_another_managers_product_or_cross_tenant_product(): void
    {
        [$owner, $vendor] = $this->createActor('vendor_product_manager');
        [$otherManager] = $this->createActor('vendor_product_manager', 'primary', $vendor);
        $product = $this->createProduct($owner);

        $this->authenticated($otherManager)
            ->getJson('/api/v1/products/'.$product->getKey())
            ->assertNotFound();

        $this->authenticated($otherManager)
            ->patchJson('/api/v1/products/'.$product->getKey(), [
                'expected_version' => 1,
                'name' => 'Unauthorized update',
                'slug' => $product->slug,
                'description' => 'Another manager cannot mutate this product.',
                'reason' => 'Test product-level creator scoping.',
            ])
            ->assertNotFound();

        $this->authenticated($otherManager)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        [$otherTenantManager] = $this->createActor('vendor_product_manager', 'other-vendor');
        $this->authenticated($otherTenantManager)
            ->getJson('/api/v1/products/'.$product->getKey())
            ->assertNotFound();
    }

    public function test_product_update_requires_the_expected_resource_version_and_rejects_protected_fields(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');
        $product = $this->createProduct($manager);

        $this->authenticated($manager)
            ->patchJson('/api/v1/products/'.$product->getKey(), [
                'expected_version' => 9,
                'name' => 'Stale update',
                'slug' => $product->slug,
                'description' => 'Must not overwrite a newer version.',
                'reason' => 'Exercise optimistic concurrency protection.',
            ])
            ->assertStatus(409);

        $this->authenticated($manager)
            ->patchJson('/api/v1/products/'.$product->getKey(), [
                'expected_version' => 1,
                'name' => 'Protected field attempt',
                'slug' => $product->slug,
                'description' => 'Must not change protected metadata.',
                'status' => 'active',
                'vendor_account_id' => str()->ulid()->toBase32(),
                'created_by_user_id' => str()->ulid()->toBase32(),
                'reason' => 'Attempt to mutate server-controlled fields.',
            ])
            ->assertStatus(422);

        $this->assertSame('Draft product', $product->refresh()->name);
        $this->assertSame('draft', $product->status);
        $this->assertSame(1, $product->version);
    }

    public function test_product_creation_requires_fresh_password_confirmation_and_mfa_step_up(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');

        $this->actingAs($manager, 'web')
            ->withSession([
                'auth_session_version' => (int) $manager->session_version,
                'auth.password_confirmed_at' => (int) now()->timestamp,
            ])
            ->postJson('/api/v1/products', [
                'name' => 'Locked Product',
                'slug' => 'locked-product',
                'description' => 'No write without fresh MFA.',
                'reason' => 'Test the privileged mutation gate.',
            ])
            ->assertStatus(423);

        $this->assertDatabaseMissing('products', ['slug' => 'locked-product']);
    }

    public function test_bootstrap_super_admin_cannot_create_or_mutate_vendor_products(): void
    {
        [$admin, $vendor] = $this->createActor('super_admin');
        PlatformInstallation::query()->updateOrCreate(
            ['installation_key' => 'primary'],
            [
                'bootstrapped_user_id' => $admin->getKey(),
                'bootstrapped_at' => now(),
            ],
        );

        $this->authenticated($admin)
            ->postJson('/api/v1/products', [
                'name' => 'Bootstrap Product',
                'slug' => 'bootstrap-product',
                'description' => 'Bootstrap administrator must remain read-only.',
                'reason' => 'Attempt forbidden product creation.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('products', [
            'vendor_account_id' => $vendor->getKey(),
            'slug' => 'bootstrap-product',
        ]);
    }

    public function test_product_audit_verifier_detects_payload_tampering(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL/MariaDB triggers prevent product-event mutation; SQLite exercises cross-record tamper detection.');
        }

        [$manager] = $this->createActor('vendor_product_manager');
        $product = $this->createProduct($manager);

        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());

        $event = ProductEvent::query()->where('product_id', $product->getKey())->firstOrFail();

        try {
            $event->update(['reason' => 'Eloquent mutation attempt']);
            $this->fail('Eloquent product event update should be rejected.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $event->delete();
            $this->fail('Eloquent product event delete should be rejected.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        DB::table('product_events')->where('id', $event->getKey())->update([
            'reason' => 'Tampered after append',
        ]);

        $this->assertFalse(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_vendor_super_admin_can_assign_and_revoke_product_access_for_an_active_developer(): void
    {
        [$creator, $vendor] = $this->createActor('vendor_product_manager');
        [$vendorAdmin] = $this->createActor('super_admin', 'primary', $vendor);
        [$developer] = $this->createActor('vendor_developer', 'primary', $vendor);
        $product = $this->createProduct($creator);
        $developerMembership = $developer->vendorMemberships()->firstOrFail();

        $this->authenticated($vendorAdmin)
            ->postJson('/api/v1/products/'.$product->getKey().'/assignments', [
                'vendor_membership_id' => $developerMembership->getKey(),
                'reason' => 'Assign the developer to the approved product.',
            ], ['X-Request-ID' => 'product-assignment-grant-0001'])
            ->assertCreated();

        $this->authenticated($developer)
            ->getJson('/api/v1/products/'.$product->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', (string) $product->getKey());

        $this->authenticated($developer)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->authenticated($developer)
            ->patchJson('/api/v1/products/'.$product->getKey(), [
                'expected_version' => 1,
                'name' => 'Developer must not edit catalogue metadata',
                'slug' => $product->slug,
                'description' => 'Product developers have read-only catalogue access.',
                'reason' => 'Attempt an unauthorized product edit.',
            ])
            ->assertNotFound();

        $this->authenticated($vendorAdmin)
            ->deleteJson('/api/v1/products/'.$product->getKey().'/assignments/'.$developerMembership->getKey(), [
                'reason' => 'Remove the developer from the product.',
            ], ['X-Request-ID' => 'product-assignment-revoke-0001'])
            ->assertNoContent();

        $this->authenticated($developer)
            ->getJson('/api/v1/products/'.$product->getKey())
            ->assertNotFound();

        $this->authenticated($developer)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('platform_access_audit_events', [
            'action' => 'product.assignment_granted',
            'request_id' => 'product-assignment-grant-0001',
        ]);
        $this->assertDatabaseHas('platform_access_audit_events', [
            'action' => 'product.assignment_revoked',
            'request_id' => 'product-assignment-revoke-0001',
        ]);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_product_manager_cannot_assign_products_and_duplicate_assignments_are_idempotent(): void
    {
        [$creator, $vendor] = $this->createActor('vendor_product_manager');
        [$otherManager] = $this->createActor('vendor_product_manager', 'primary', $vendor);
        $product = $this->createProduct($creator);
        $targetMembership = $otherManager->vendorMemberships()->firstOrFail();

        $this->authenticated($creator)
            ->postJson('/api/v1/products/'.$product->getKey().'/assignments', [
                'vendor_membership_id' => $targetMembership->getKey(),
                'reason' => 'A product manager cannot grant assignments.',
            ])
            ->assertForbidden();

        [$vendorAdmin] = $this->createActor('super_admin', 'primary', $vendor);
        $payload = [
            'vendor_membership_id' => $targetMembership->getKey(),
            'reason' => 'Assign the manager to the product.',
        ];

        $this->authenticated($vendorAdmin)
            ->postJson('/api/v1/products/'.$product->getKey().'/assignments', $payload)
            ->assertCreated();

        $this->authenticated($vendorAdmin)
            ->postJson('/api/v1/products/'.$product->getKey().'/assignments', $payload)
            ->assertOk()
            ->assertJsonPath('meta.already_assigned', true);

        $this->assertDatabaseCount('product_assignment_events', 2);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_product_manager_can_create_release_channels_and_promote_published_same_product_versions(): void
    {
        [$manager, $vendor] = $this->createActor('vendor_product_manager');
        [$vendorAdmin] = $this->createActor('super_admin', 'primary', $vendor);
        [$developer] = $this->createActor('vendor_developer', 'primary', $vendor);
        $product = $this->createProduct($manager);
        $otherProduct = $this->createProduct($manager);

        $channel = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels', [
                'slug' => 'stable',
                'name' => 'Stable',
                'reason' => 'Create the stable release channel.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'stable')
            ->assertJsonPath('data.version_number', 1);

        $channelId = (string) $channel->json('data.id');

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels', [
                'slug' => 'stable',
                'name' => 'Duplicate Stable',
                'reason' => 'Attempt a duplicate release channel.',
            ])
            ->assertUnprocessable();

        $version = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/versions', [
                'version' => '3.0.0',
                'release_notes' => 'Stable release.',
                'artifact_sha256' => str_repeat('a', 64),
                'artifact_size_bytes' => 4096,
                'reason' => 'Create a stable product version.',
            ])
            ->assertCreated();

        $versionId = (string) $version->json('data.id');

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/versions/'.$versionId.'/publish', [
                'reason' => 'Publish the stable product version.',
            ])
            ->assertOk();

        $otherVersion = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$otherProduct->getKey().'/versions', [
                'version' => '1.0.0',
                'release_notes' => 'Other product release.',
                'artifact_sha256' => str_repeat('b', 64),
                'artifact_size_bytes' => 2048,
                'reason' => 'Create a different product version.',
            ])
            ->assertCreated();

        $otherVersionId = (string) $otherVersion->json('data.id');

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$otherProduct->getKey().'/versions/'.$otherVersionId.'/publish', [
                'reason' => 'Publish the other product version.',
            ])
            ->assertOk();

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels/'.$channelId.'/promote', [
                'product_version_id' => $otherVersionId,
                'expected_version' => 1,
                'reason' => 'Reject a cross-product channel promotion.',
            ])
            ->assertUnprocessable();

        $promoted = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels/'.$channelId.'/promote', [
                'product_version_id' => $versionId,
                'expected_version' => 1,
                'reason' => 'Promote the stable product version.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_product_version_id', $versionId)
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('meta.already_promoted', false);

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels/'.$channelId.'/promote', [
                'product_version_id' => $versionId,
                'expected_version' => 1,
                'reason' => 'Reject a stale channel promotion.',
            ])
            ->assertStatus(409);

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels/'.$channelId.'/promote', [
                'product_version_id' => $versionId,
                'expected_version' => 2,
                'reason' => 'Repeat the current channel promotion.',
            ])
            ->assertOk()
            ->assertJsonPath('meta.already_promoted', true);

        $this->authenticated($vendorAdmin)
            ->postJson('/api/v1/products/'.$product->getKey().'/assignments', [
                'vendor_membership_id' => $developer->vendorMemberships()->firstOrFail()->getKey(),
                'reason' => 'Assign developer access to release channels.',
            ])
            ->assertCreated();

        $this->authenticated($developer)
            ->getJson('/api/v1/products/'.$product->getKey().'/release-channels')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->authenticated($developer)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels', [
                'slug' => 'beta',
                'name' => 'Beta',
                'reason' => 'Developer cannot create release channels.',
            ])
            ->assertForbidden();

        $this->authenticated($developer)
            ->postJson('/api/v1/products/'.$product->getKey().'/release-channels/'.$channelId.'/promote', [
                'product_version_id' => $versionId,
                'expected_version' => 2,
                'reason' => 'Developer cannot promote releases.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('release_channel_events', 2);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_product_manager_can_create_update_and_publish_product_versions(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');
        $product = $this->createProduct($manager);

        $created = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/versions', [
                'version' => '1.0.0',
                'release_notes' => 'Initial stable release.',
                'artifact_sha256' => str_repeat('a', 64),
                'artifact_size_bytes' => 1024,
                'reason' => 'Create the initial product version.',
            ], ['X-Request-ID' => 'product-version-create-0001'])
            ->assertCreated()
            ->assertJsonPath('data.version', '1.0.0')
            ->assertJsonPath('data.status', 'draft');

        $versionId = (string) $created->json('data.id');

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/versions', [
                'version' => '1.0.0',
                'release_notes' => 'Duplicate version must be rejected.',
                'reason' => 'Attempt a duplicate product version.',
            ])
            ->assertUnprocessable();

        $updated = $this->authenticated($manager)
            ->patchJson('/api/v1/products/'.$product->getKey().'/versions/'.$versionId, [
                'expected_version' => 1,
                'release_notes' => 'Updated release notes.',
                'artifact_sha256' => str_repeat('b', 64),
                'artifact_size_bytes' => 2048,
                'reason' => 'Update the draft release metadata.',
            ])
            ->assertOk()
            ->assertJsonPath('data.version_number', 2);

        $this->authenticated($manager)
            ->patchJson('/api/v1/products/'.$product->getKey().'/versions/'.$versionId, [
                'expected_version' => 1,
                'release_notes' => 'Stale update.',
                'artifact_sha256' => str_repeat('c', 64),
                'artifact_size_bytes' => 4096,
                'reason' => 'Attempt a stale version update.',
            ])
            ->assertStatus(409);

        $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/versions/'.$versionId.'/publish', [
                'reason' => 'Publish the verified product version.',
            ], ['X-Request-ID' => 'product-version-publish-0001'])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->authenticated($manager)
            ->patchJson('/api/v1/products/'.$product->getKey().'/versions/'.$versionId, [
                'expected_version' => 3,
                'release_notes' => 'Published versions are immutable.',
                'artifact_sha256' => str_repeat('d', 64),
                'artifact_size_bytes' => 8192,
                'reason' => 'Attempt to mutate a published version.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('product_version_events', [
            'product_version_id' => $versionId,
            'event_type' => 'published',
            'request_id' => 'product-version-publish-0001',
        ]);
        $this->assertTrue(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_assigned_developer_can_read_product_versions_but_cannot_create_or_update_them(): void
    {
        [$manager, $vendor] = $this->createActor('vendor_product_manager');
        [$vendorAdmin] = $this->createActor('super_admin', 'primary', $vendor);
        [$developer] = $this->createActor('vendor_developer', 'primary', $vendor);
        $product = $this->createProduct($manager);

        $this->authenticated($vendorAdmin)
            ->postJson('/api/v1/products/'.$product->getKey().'/assignments', [
                'vendor_membership_id' => $developer->vendorMemberships()->firstOrFail()->getKey(),
                'reason' => 'Assign developer access for version review.',
            ])
            ->assertCreated();

        $created = $this->authenticated($manager)
            ->postJson('/api/v1/products/'.$product->getKey().'/versions', [
                'version' => '2.0.0-beta.1',
                'release_notes' => 'Beta release for developer review.',
                'artifact_sha256' => str_repeat('e', 64),
                'artifact_size_bytes' => 4096,
                'reason' => 'Create the beta product version.',
            ])
            ->assertCreated();

        $versionId = (string) $created->json('data.id');

        $this->authenticated($developer)
            ->getJson('/api/v1/products/'.$product->getKey().'/versions')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->authenticated($developer)
            ->getJson('/api/v1/products/'.$product->getKey().'/versions/'.$versionId)
            ->assertOk();

        $this->authenticated($developer)
            ->postJson('/api/v1/products/'.$product->getKey().'/versions', [
                'version' => '2.0.0-beta.2',
                'reason' => 'Developer must not create versions.',
            ])
            ->assertForbidden();
    }

    public function test_product_assignment_metadata_cannot_be_rewritten_without_a_state_transition(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');
        $product = $this->createProduct($manager);
        $assignment = ProductAssignment::query()
            ->where('product_id', $product->getKey())
            ->firstOrFail();

        $this->expectException(LogicException::class);

        $assignment->forceFill(['assigned_at' => now()->addMinute()])->save();
    }

    public function test_product_audit_verifier_rejects_unreferenced_product_events(): void
    {
        [$manager] = $this->createActor('vendor_product_manager');
        $product = $this->createProduct($manager);

        ProductEvent::query()->create([
            'product_id' => $product->getKey(),
            'vendor_account_id' => $product->vendor_account_id,
            'actor_user_id' => $manager->getKey(),
            'event_type' => 'updated',
            'reason' => 'Unlinked event must invalidate audit evidence.',
            'changed_fields' => ['name'],
            'change_digest' => str_repeat('0', 64),
            'request_id' => 'unlinked-product-event-0001',
            'created_at' => now(),
        ]);

        $this->assertFalse(app(PlatformAccessAuditChainVerifier::class)->verify());
    }

    public function test_mysql_and_mariadb_reject_raw_product_event_updates_and_deletes(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Database-level immutable triggers are specific to MySQL/MariaDB.');
        }

        [$manager] = $this->createActor('vendor_product_manager');
        $product = $this->createProduct($manager);
        $event = ProductEvent::query()->where('product_id', $product->getKey())->firstOrFail();

        try {
            DB::table('product_events')->where('id', $event->getKey())->update([
                'reason' => 'Raw database mutation',
            ]);
            $this->fail('Product event update should be rejected by the database trigger.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('product_events')->where('id', $event->getKey())->delete();
            $this->fail('Product event deletion should be rejected by the database trigger.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function createProduct(User $manager): Product
    {
        $response = $this->authenticated($manager)->postJson('/api/v1/products', [
            'name' => 'Draft product',
            'slug' => 'draft-product-'.str()->lower(str()->random(8)),
            'description' => 'A product fixture for authorization tests.',
            'reason' => 'Create a scoped product test fixture.',
        ]);

        $response->assertCreated();

        return Product::query()->whereKey((string) $response->json('data.id'))->firstOrFail();
    }

    /**
     * @return array{User, VendorAccount}
     */
    private function createActor(
        string $roleSlug,
        string $installationKey = 'primary',
        ?VendorAccount $existingVendor = null,
    ): array {
        $vendor = $existingVendor ?? VendorAccount::query()->create([
            'installation_key' => $installationKey,
            'legal_name' => str()->headline($installationKey).' Limited',
            'display_name' => str()->headline($installationKey),
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);
        $user = User::query()->create([
            'name' => 'Product Manager',
            'email' => 'product-manager-'.str()->random(12).'@example.test',
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
