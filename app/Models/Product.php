<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class Product extends BaseModel
{
    protected $fillable = [
        'public_id',
        'vendor_account_id',
        'slug',
        'name',
        'description',
        'status',
        'version',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $product): void {
            if (
                $product->status !== 'draft'
                || (int) $product->version !== 1
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', (string) $product->public_id) !== 1
                || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', (string) $product->slug) !== 1
                || mb_strlen(trim((string) $product->name)) < 2
                || mb_strlen((string) $product->name) > 160
                || mb_strlen((string) $product->slug) > 80
                || ($product->description !== null && mb_strlen((string) $product->description) > 2000)
            ) {
                throw new LogicException('Product creation metadata is invalid.');
            }
        });

        self::updating(static function (self $product): void {
            if (
                $product->isDirty(['public_id', 'vendor_account_id', 'created_by_user_id', 'status'])
                || $product->getOriginal('status') !== 'draft'
                || (int) $product->version !== ((int) $product->getOriginal('version') + 1)
            ) {
                throw new LogicException('Product identity, ownership, lifecycle and version metadata are service-controlled.');
            }
        });
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasMany<ProductEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ProductEvent::class);
    }
}
