<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class LicenseEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
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
                ! in_array($event->actor_type, ['user', 'system'], true)
                || ($event->actor_type === 'user' && $event->actor_user_id === null)
                || ($event->actor_type === 'system' && $event->actor_user_id !== null)
                || ! in_array($event->event_type, ['issued', 'secret_delivered', 'activated', 'suspended', 'reinstated', 'revoked', 'expired', 'renewed', 'key_rotated', 'transferred', 'replaced'], true)
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $event->payload_sha256) !== 1
                || trim((string) $event->reason) === ''
                || trim((string) $event->request_id) === ''
            ) {
                throw new LogicException('Licence event metadata is invalid.');
            }

            $license = License::query()
                ->whereKey($event->license_id)
                ->where('vendor_account_id', $event->vendor_account_id)
                ->where('product_id', $event->product_id)
                ->exists();

            if (! $license) {
                throw new LogicException('Licence event scope does not match its licence.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Licence events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Licence events are immutable.');
        });
    }

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
