<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $public_id
 * @property string $vendor_account_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property int $version
 * @property string $created_by_user_id
 * @property string|null $updated_by_user_id
 */
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
            $originalStatus = (string) $product->getRawOriginal('status');
            $isActivation = $product->isDirty('status')
                && $originalStatus === 'draft'
                && $product->status === 'active';

            if (
                $product->isDirty(['public_id', 'vendor_account_id', 'created_by_user_id'])
                || $originalStatus !== 'draft'
                || (int) $product->version !== ((int) $product->getRawOriginal('version') + 1)
            ) {
                throw new LogicException('Product identity, ownership, lifecycle and version metadata are service-controlled.');
            }

            if ($isActivation) {
                $hasPublishedRelease = $product->releaseChannels()
                    ->where('status', 'active')
                    ->whereNotNull('current_product_version_id')
                    ->whereHas('currentVersion', static fn ($query) => $query->where('status', 'published'))
                    ->exists();

                if (
                    ! $hasPublishedRelease
                    || $product->isDirty(['name', 'slug', 'description'])
                ) {
                    throw new LogicException('A product may activate only after a published product version is promoted to an active release channel.');
                }

                return;
            }

            if ($product->isDirty('status')) {
                throw new LogicException('The product lifecycle transition is invalid.');
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

    /** @return HasMany<ProductAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(ProductAssignment::class);
    }

    /** @return HasMany<ProductVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class);
    }

    /** @return HasMany<ReleaseChannel, $this> */
    public function releaseChannels(): HasMany
    {
        return $this->hasMany(ReleaseChannel::class);
    }
}
