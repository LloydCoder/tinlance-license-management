<?php

declare(strict_types=1);

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use LogicException;

/**
 * @property list<string> $changed_fields
 * @property string $reason
 * @property string $event_type
 * @property string $request_id
 * @property string $change_digest
 */
final class VendorAccountProfileEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'vendor_account_id',
        'actor_user_id',
        'event_type',
        'reason',
        'changed_fields',
        'change_digest',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            if ($event->event_type !== 'profile_updated') {
                throw new LogicException('Vendor account profile event type is invalid.');
            }

            if (
                trim($event->reason) === ''
                || mb_strlen($event->reason) > 500
                || preg_match('/\\A[A-Za-z0-9._:-]{1,128}\\z/D', (string) $event->request_id) !== 1
                || preg_match('/\\A[a-f0-9]{64}\\z/D', (string) $event->change_digest) !== 1
            ) {
                throw new LogicException('Vendor account profile event metadata is invalid.');
            }

            if ($event->changed_fields !== ['display_name']) {
                throw new LogicException('Vendor account profile event contains an invalid field.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Vendor account profile events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Vendor account profile events are immutable.');
        });
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
