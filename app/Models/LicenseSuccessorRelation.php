<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $source_license_id
 * @property string $successor_license_id
 * @property string $vendor_account_id
 * @property string $product_id
 * @property string $source_customer_organization_id
 * @property string $destination_customer_organization_id
 * @property string $actor_user_id
 * @property string $relation_type
 * @property string $reason
 * @property string $idempotency_key_sha256
 * @property string $request_fingerprint_sha256
 * @property string $request_id
 * @property string $payload_sha256
 * @property array<string, mixed> $event_payload
 * @property Carbon $created_at
 */
final class LicenseSuccessorRelation extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'source_license_id',
        'successor_license_id',
        'vendor_account_id',
        'product_id',
        'source_customer_organization_id',
        'destination_customer_organization_id',
        'actor_user_id',
        'relation_type',
        'reason',
        'idempotency_key_sha256',
        'request_fingerprint_sha256',
        'request_id',
        'payload_sha256',
        'event_payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $relation): void {
            if (
                ! in_array($relation->relation_type, ['transfer', 'replacement'], true)
                || trim((string) $relation->reason) === ''
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $relation->idempotency_key_sha256) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $relation->request_fingerprint_sha256) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $relation->payload_sha256) !== 1
                || trim((string) $relation->request_id) === ''
            ) {
                throw new LogicException('Licence successor relation metadata is invalid.');
            }

            $source = License::query()
                ->whereKey($relation->source_license_id)
                ->where('vendor_account_id', $relation->vendor_account_id)
                ->where('product_id', $relation->product_id)
                ->where('customer_organization_id', $relation->source_customer_organization_id)
                ->where('status', 'revoked')
                ->exists();
            $successor = License::query()
                ->whereKey($relation->successor_license_id)
                ->where('vendor_account_id', $relation->vendor_account_id)
                ->where('product_id', $relation->product_id)
                ->where('customer_organization_id', $relation->destination_customer_organization_id)
                ->where('status', 'issued')
                ->exists();

            if (! $source || ! $successor) {
                throw new LogicException('Licence successor relation must connect a revoked source to an issued successor in the same vendor and product scope.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Licence successor relations are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Licence successor relations are immutable.');
        });
    }

    /** @return BelongsTo<License, $this> */
    public function sourceLicense(): BelongsTo
    {
        return $this->belongsTo(License::class, 'source_license_id');
    }

    /** @return BelongsTo<License, $this> */
    public function successorLicense(): BelongsTo
    {
        return $this->belongsTo(License::class, 'successor_license_id');
    }
}
