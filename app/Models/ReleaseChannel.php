<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $product_id
 * @property string $vendor_account_id
 * @property string $slug
 * @property string $name
 * @property string $status
 * @property string|null $current_product_version_id
 * @property int $version_number
 * @property string $created_by_user_id
 * @property string $updated_by_user_id
 */
final class ReleaseChannel extends BaseModel
{
    protected $fillable = [
        'product_id',
        'vendor_account_id',
        'slug',
        'name',
        'status',
        'current_product_version_id',
        'version_number',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['version_number' => 'integer'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $channel): void {
            if (
                preg_match('/\A[a-z][a-z0-9-]{1,31}\z/D', (string) $channel->slug) !== 1
                || mb_strlen(trim((string) $channel->name)) < 2
                || mb_strlen((string) $channel->name) > 100
                || $channel->status !== 'active'
                || (int) $channel->version_number !== 1
                || $channel->current_product_version_id !== null
            ) {
                throw new LogicException('Release channel metadata is invalid.');
            }
        });

        self::updating(static function (self $channel): void {
            if (
                $channel->isDirty(['product_id', 'vendor_account_id', 'slug', 'name', 'created_by_user_id', 'status'])
                || (int) $channel->version_number !== ((int) $channel->getRawOriginal('version_number') + 1)
                || $channel->current_product_version_id === $channel->getRawOriginal('current_product_version_id')
            ) {
                throw new LogicException('Release channels may only advance through a versioned promotion.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Release channels are retained and cannot be deleted.');
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'current_product_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
