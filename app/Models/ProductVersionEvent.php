<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $product_version_id
 * @property string $product_id
 * @property string $vendor_account_id
 * @property string $actor_user_id
 * @property string $event_type
 * @property string $reason
 * @property list<string> $changed_fields
 * @property string $change_digest
 * @property string $request_id
 */
final class ProductVersionEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'product_version_id',
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
                ! in_array($event->event_type, ['created', 'updated', 'published'], true)
                || mb_strlen(trim((string) $event->reason)) < 5
                || mb_strlen((string) $event->reason) > 500
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', (string) $event->request_id) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $event->change_digest) !== 1
                || $event->changed_fields === []
                || count(array_unique($event->changed_fields)) !== count($event->changed_fields)
                || array_diff($event->changed_fields, ['version', 'status', 'release_notes', 'artifact_sha256', 'artifact_size_bytes', 'published_at']) !== []
            ) {
                throw new LogicException('Product version event metadata is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Product version events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Product version events are immutable.');
        });
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function productVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
