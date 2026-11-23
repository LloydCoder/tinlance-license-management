<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $allocation_id
 * @property string $license_id
 * @property string $vendor_account_id
 * @property string $product_id
 * @property string $actor_user_id
 * @property string $event_type
 * @property string $reason
 * @property string $request_id
 * @property string $payload_sha256
 * @property array<string, mixed> $event_payload
 */
final class LicenseAllocationEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'allocation_id',
        'license_id',
        'vendor_account_id',
        'product_id',
        'actor_user_id',
        'event_type',
        'reason',
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
        self::creating(static function (self $event): void {
            if (
                ! in_array($event->event_type, ['allocated', 'released'], true)
                || trim((string) $event->reason) === ''
                || trim((string) $event->request_id) === ''
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $event->payload_sha256) !== 1
            ) {
                throw new LogicException('Licence allocation event metadata is invalid.');
            }

            $allocation = LicenseAllocation::query()
                ->whereKey($event->allocation_id)
                ->where('license_id', $event->license_id)
                ->where('vendor_account_id', $event->vendor_account_id)
                ->where('product_id', $event->product_id)
                ->exists();

            if (! $allocation) {
                throw new LogicException('Licence allocation event scope does not match its allocation.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Licence allocation events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Licence allocation events are immutable.');
        });
    }

    /** @return BelongsTo<LicenseAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LicenseAllocation::class);
    }
}
