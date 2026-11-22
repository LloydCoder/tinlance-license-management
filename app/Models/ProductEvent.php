<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property list<string> $changed_fields
 * @property string $reason
 * @property string $event_type
 * @property string $request_id
 * @property string $change_digest
 */
final class ProductEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'product_id',
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
            if (
                ! in_array($event->event_type, ['created', 'updated'], true)
                || mb_strlen(trim((string) $event->reason)) < 5
                || mb_strlen((string) $event->reason) > 500
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', (string) $event->request_id) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $event->change_digest) !== 1
                || ! array_is_list($event->changed_fields)
                || $event->changed_fields === []
                || count(array_unique($event->changed_fields)) !== count($event->changed_fields)
                || array_diff($event->changed_fields, ['name', 'slug', 'description']) !== []
            ) {
                throw new LogicException('Product event metadata is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Product events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Product events are immutable.');
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
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
