<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $product_id
 * @property string $vendor_account_id
 * @property string $vendor_membership_id
 * @property string $assigned_by_user_id
 * @property Carbon $assigned_at
 * @property Carbon|null $revoked_at
 */
final class ProductAssignment extends BaseModel
{
    protected $fillable = [
        'product_id',
        'vendor_account_id',
        'vendor_membership_id',
        'assigned_by_user_id',
        'assigned_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $assignment): void {
            if ($assignment->revoked_at !== null) {
                throw new LogicException('A new product assignment must be active.');
            }
        });

        self::updating(static function (self $assignment): void {
            if (
                $assignment->isDirty(['product_id', 'vendor_account_id', 'vendor_membership_id'])
                || ! $assignment->isDirty(['assigned_by_user_id', 'assigned_at', 'revoked_at'])
            ) {
                throw new LogicException('Product assignment identity is immutable.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Product assignments must be revoked, not deleted.');
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<VendorMembership, $this> */
    public function vendorMembership(): BelongsTo
    {
        return $this->belongsTo(VendorMembership::class, 'vendor_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
