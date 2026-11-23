<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LicenseIssuanceIdempotencySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_idempotency_records_store_only_hashed_keys_and_encrypted_responses(): void
    {
        $this->assertTrue(Schema::hasColumns('license_issuance_requests', [
            'id',
            'vendor_account_id',
            'actor_user_id',
            'operation',
            'idempotency_key_sha256',
            'request_fingerprint_sha256',
            'status',
            'license_id',
            'response_ciphertext',
            'expires_at',
            'created_at',
            'updated_at',
        ]));

        $columns = Schema::getColumnListing('license_issuance_requests');
        $this->assertNotContains('idempotency_key', $columns);
        $this->assertNotContains('plaintext_key', $columns);
    }
}
