<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $release_channel_id
 * @property string $product_id
 * @property string $vendor_account_id
 * @property string $actor_user_id
 * @property string $event_type
 * @property string|null $previous_product_version_id
 * @property string|null $product_version_id
 * @property string $reason
 * @property string $request_id
 */
final class ReleaseChannelEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'release_channel_id',
        'product_id',
        'vendor_account_id',
        'actor_user_id',
        'event_type',
        'previous_product_version_id',
        'product_version_id',
        'reason',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            if (
                ! in_array($event->event_type, ['created', 'promoted'], true)
                || mb_strlen(trim((string) $event->reason)) < 5
                || mb_strlen((string) $event->reason) > 500
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', (string) $event->request_id) !== 1
                || ($event->event_type === 'created' && ($event->previous_product_version_id !== null || $event->product_version_id !== null))
                || ($event->event_type === 'promoted' && $event->product_version_id === null)
            ) {
                throw new LogicException('Release channel event metadata is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Release channel events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Release channel events are immutable.');
        });
    }

    /** @return BelongsTo<ReleaseChannel, $this> */
    public function releaseChannel(): BelongsTo
    {
        return $this->belongsTo(ReleaseChannel::class, 'release_channel_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function productVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function previousProductVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'previous_product_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
