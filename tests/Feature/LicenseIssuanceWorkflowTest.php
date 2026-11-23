<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerOrganization;
use App\Models\License;
use App\Models\LicenseEvent;
use App\Models\LicenseIssuanceRequest;
use App\Models\PlanVersion;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\VendorAccount;
use App\Models\VendorMembership;
use App\Services\Licensing\LicenseKeyMaterialProvider;
use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Tests\TestCase;
use Tlms\Domain\Licensing\LicenseKey;

final class LicenseIssuanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->withoutMiddleware([
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            'throttle:api',
            'throttle:5,1',
            'throttle:3,1',
        ]);
        $this->configureKeyMaterial();
    }

    public function test_issuance_is_atomic_idempotent_and_reveals_the_secret_only_once(): void
    {
        [$actor, $vendor, $product, $planVersion, $customer] = $this->createIssuanceFixture();
        $payload = [
            'customer_organization_id' => $customer->getKey(),
            'plan_version_id' => $planVersion->getKey(),
            'starts_at' => '2030-01-01T00:00:00Z',
            'reason' => 'Customer payment confirmed for the annual professional plan.',
        ];
        $idempotencyKey = str()->random(40);
        $headers = [
            'Idempotency-Key' => $idempotencyKey,
            'X-Request-ID' => 'license-issue-request-000001',
        ];

        $created = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$product->getKey().'/licenses', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('issuance.secret_available', true)
            ->assertJsonPath('issuance.secret_delivery', 'one_time')
            ->assertJsonMissingPath('data.license_key');

        $licenseId = (string) $created->json('data.id');
        $issuanceRequestId = (string) $created->json('issuance.request_id');

        $this->assertDatabaseHas('licenses', [
            'id' => $licenseId,
            'vendor_account_id' => $vendor->getKey(),
            'product_id' => $product->getKey(),
            'customer_organization_id' => $customer->getKey(),
            'plan_version_id' => $planVersion->getKey(),
            'status' => 'issued',
        ]);
        $this->assertDatabaseHas('license_events', [
            'license_id' => $licenseId,
            'event_type' => 'issued',
            'request_id' => 'license-issue-request-000001',
        ]);

        $pendingRequest = LicenseIssuanceRequest::query()->findOrFail($issuanceRequestId);
        $this->assertSame(hash('sha256', $idempotencyKey), $pendingRequest->idempotency_key_sha256);
        $this->assertNotNull($pendingRequest->response_ciphertext);
        $this->assertStringNotContainsString('tlms_', (string) $pendingRequest->response_ciphertext);

        $retry = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$product->getKey().'/licenses', $payload, [
                'Idempotency-Key' => $headers['Idempotency-Key'],
                'X-Request-ID' => 'license-issue-retry-000001',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $licenseId)
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('issuance.secret_available', true);

        $this->assertSame(1, License::query()->count());

        $secretResponse = $this->authenticated($actor)
            ->postJson('/api/v1/license-issuance-requests/'.$issuanceRequestId.'/secret', [], [
                'X-Request-ID' => 'license-secret-delivery-000001',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private, max-age=0')
            ->assertHeader('Pragma', 'no-cache')
            ->assertJsonPath('data.delivery', 'one_time');

        $plaintext = (string) $secretResponse->json('data.license_key');
        $this->assertMatchesRegularExpression('/\Atlms_[A-Za-z0-9_-]{43}\z/D', $plaintext);

        $license = License::query()->findOrFail($licenseId);
        $pepper = app(LicenseKeyMaterialProvider::class)->activePepperMaterial();
        $this->assertTrue(LicenseKey::verify($plaintext, (string) $license->key_verifier, $pepper, 'pepper-test-v1'));
        $this->assertSame('2031-01-01', $license->expires_at?->toDateString());

        $issuanceRequest = LicenseIssuanceRequest::query()->findOrFail($issuanceRequestId);
        $this->assertSame('delivered', $issuanceRequest->status);
        $this->assertNull($issuanceRequest->response_ciphertext);
        $this->assertNotNull($issuanceRequest->response_delivered_at);

        $this->assertDatabaseHas('license_events', [
            'license_id' => $licenseId,
            'event_type' => 'secret_delivered',
            'request_id' => 'license-secret-delivery-000001',
        ]);

        $storedData = json_encode([
            'license' => $license->toArray(),
            'events' => LicenseEvent::query()->where('license_id', $licenseId)->get()->toArray(),
            'request' => $issuanceRequest->toArray(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($plaintext, $storedData);

        $this->authenticated($actor)
            ->postJson('/api/v1/license-issuance-requests/'.$issuanceRequestId.'/secret', [], [
                'X-Request-ID' => 'license-secret-delivery-000002',
            ])
            ->assertStatus(410);

        $afterDeliveryRetry = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$product->getKey().'/licenses', $payload, [
                'Idempotency-Key' => $headers['Idempotency-Key'],
                'X-Request-ID' => 'license-issue-retry-000002',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $licenseId)
            ->assertJsonPath('issuance.secret_available', false);

        $this->assertSame(1, License::query()->count());
        $this->assertSame(2, LicenseEvent::query()->where('license_id', $licenseId)->count());
    }

    public function test_idempotency_payload_mismatch_and_reused_event_request_id_do_not_create_duplicate_licences(): void
    {
        [$actor, , $product, $planVersion, $customer] = $this->createIssuanceFixture();
        $payload = [
            'customer_organization_id' => $customer->getKey(),
            'plan_version_id' => $planVersion->getKey(),
            'reason' => 'Customer payment confirmed for the annual professional plan.',
        ];
        $idempotencyKey = str()->random(40);
        $requestId = 'license-issue-request-000002';

        $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$product->getKey().'/licenses', $payload, [
                'Idempotency-Key' => $idempotencyKey,
                'X-Request-ID' => $requestId,
            ])
            ->assertCreated();

        $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$product->getKey().'/licenses', [
                ...$payload,
                'reason' => 'A materially different reason for this issuance request.',
            ], [
                'Idempotency-Key' => $idempotencyKey,
                'X-Request-ID' => 'license-issue-retry-000003',
            ])
            ->assertStatus(409);

        $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$product->getKey().'/licenses', $payload, [
                'Idempotency-Key' => str()->random(40),
                'X-Request-ID' => $requestId,
            ])
            ->assertStatus(409);

        $this->assertSame(1, License::query()->count());
        $this->assertSame(1, LicenseIssuanceRequest::query()->count());
    }

    public function test_licence_issuance_is_denied_across_vendor_boundaries(): void
    {
        [$actor, , $product, $planVersion, $customer] = $this->createIssuanceFixture();
        [$foreignActor] = $this->createActor('Foreign Licence Vendor');

        $this->authenticated($foreignActor)
            ->postJson('/api/v1/products/'.$product->getKey().'/licenses', [
                'customer_organization_id' => $customer->getKey(),
                'plan_version_id' => $planVersion->getKey(),
                'reason' => 'Attempt cross-vendor licence issuance without authorization.',
            ], [
                'Idempotency-Key' => str()->random(40),
                'X-Request-ID' => 'foreign-license-request-0001',
            ])
            ->assertForbidden();

        $this->assertSame(0, License::query()->count());
    }

    /**
     * @return array{User, VendorAccount, Product, PlanVersion, CustomerOrganization}
     */
    private function createIssuanceFixture(): array
    {
        [$actor, $vendor] = $this->createActor('TLMS Licence Vendor');

        $productId = $this->authenticated($actor)
            ->postJson('/api/v1/products', [
                'name' => 'Licence Workflow Product',
                'slug' => 'licence-workflow-product',
                'description' => 'Transactional licence issuance fixture.',
                'reason' => 'Create the licence workflow product.',
            ])
            ->assertCreated()
            ->json('data.id');

        $productVersionId = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/versions', [
                'version' => '1.0.0',
                'release_notes' => 'Initial production release.',
                'artifact_sha256' => hash('sha256', 'licence-workflow-artifact'),
                'artifact_size_bytes' => 128,
                'reason' => 'Create the first product version.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/versions/'.$productVersionId.'/publish', [
                'reason' => 'Publish the initial production version.',
            ])
            ->assertOk();

        $channelId = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/release-channels', [
                'slug' => 'stable',
                'name' => 'Stable',
                'reason' => 'Create the stable release channel.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/release-channels/'.$channelId.'/promote', [
                'product_version_id' => $productVersionId,
                'expected_version' => 1,
                'reason' => 'Promote the initial production version.',
            ])
            ->assertOk();

        $planId = $this->authenticated($actor)
            ->postJson('/api/v1/products/'.$productId.'/plans', [
                'code' => 'annual-professional',
                'name' => 'Annual Professional',
                'reason' => 'Create the annual professional plan.',
            ])
            ->assertCreated()
            ->json('data.id');

        $planVersionId = $this->authenticated($actor)
            ->postJson('/api/v1/plans/'.$planId.'/versions', [
                'expected_version' => 1,
                'billing_model' => 'annual',
                'currency' => 'NGN',
                'price_minor' => 500000,
                'billing_interval' => null,
                'trial_days' => 0,
                'maintenance_days' => 30,
                'entitlements' => [
                    ['key' => 'seats', 'type' => 'integer', 'value' => 10],
                    ['key' => 'offline', 'type' => 'boolean', 'value' => true],
                ],
                'reason' => 'Create the annual professional plan version.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->authenticated($actor)
            ->postJson('/api/v1/plans/'.$planId.'/versions/'.$planVersionId.'/publish', [
                'expected_version' => 2,
                'reason' => 'Publish the annual professional plan version.',
            ])
            ->assertOk();

        $customer = CustomerOrganization::query()->create([
            'vendor_account_id' => $vendor->getKey(),
            'external_reference' => 'licence-workflow-customer',
            'legal_name' => 'Licence Workflow Customer Limited',
            'display_name' => 'Licence Workflow Customer',
            'status' => 'active',
            'billing_email' => 'billing@licence-workflow.example',
        ]);

        /** @var Product $product */
        $product = Product::query()->whereKey($productId)->firstOrFail();
        /** @var PlanVersion $planVersion */
        $planVersion = PlanVersion::query()->whereKey($planVersionId)->firstOrFail();

        return [$actor, $vendor, $product, $planVersion, $customer];
    }

    /**
     * @return array{User, VendorAccount}
     */
    private function createActor(string $vendorName): array
    {
        $vendor = VendorAccount::query()->create([
            'installation_key' => $vendorName === 'TLMS Licence Vendor' ? 'primary' : 'foreign-'.str()->random(12),
            'legal_name' => $vendorName.' Limited',
            'display_name' => $vendorName,
            'status' => 'active',
            'default_currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
        ]);

        $user = User::query()->create([
            'name' => $vendorName.' Admin',
            'email' => 'licence-admin-'.str()->random(12).'@example.test',
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

    private function configureKeyMaterial(): void
    {
        config([
            'tlms.licensing.key_peppers_json' => json_encode([
                'pepper-test-v1' => $this->base64UrlKey(),
            ], JSON_THROW_ON_ERROR),
            'tlms.licensing.active_pepper_key_id' => 'pepper-test-v1',
            'tlms.licensing.response_encryption_keys_json' => json_encode([
                'response-test-v1' => $this->base64UrlKey(),
            ], JSON_THROW_ON_ERROR),
            'tlms.licensing.active_response_encryption_key_id' => 'response-test-v1',
            'tlms.licensing.issuance_secret_ttl_seconds' => 900,
        ]);
    }

    private function base64UrlKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
