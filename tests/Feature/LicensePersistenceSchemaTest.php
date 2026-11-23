<?php

declare(strict_types=1);

namespace Tests\\Feature;

use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Schema;
use Tests\\TestCase;

final class LicensePersistenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_license_aggregate_and_immutable_event_stream_have_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('licenses', [
            'id',
            'vendor_account_id',
            'customer_organization_id',
            'product_id',
            'plan_version_id',
            'public_reference',
            'key_verifier',
            'pepper_key_id',
            'status',
            'starts_at',
            'expires_at',
            'grace_until',
            'version',
            'created_by_user_id',
        ]));

        $this->assertTrue(Schema::hasColumns('license_events', [
            'id',
            'license_id',
            'vendor_account_id',
            'product_id',
            'actor_type',
            'actor_user_id',
            'event_type',
            'from_status',
            'to_status',
            'reason',
            'request_id',
            'payload_sha256',
            'event_payload',
            'created_at',
        ]));
    }
}
