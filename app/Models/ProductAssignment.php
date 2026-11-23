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
            $productVendorId = Product::query()->whereKey($assignment->product_id)->value('vendor_account_id');
            $membershipVendorId = VendorMembership::query()->whereKey($assignment->vendor_membership_id)->value('vendor_account_id');

            if (
                $assignment->revoked_at !== null
                || (string) $productVendorId !== (string) $assignment->vendor_account_id
                || (string) $membershipVendorId !== (string) $assignment->vendor_account_id
            ) {
                throw new LogicException('A product assignment must be active and remain within one vendor tenant.');
            }
        });

        self::updating(static function (self $assignment): void {
            if ($assignment->isDirty(['product_id', 'vendor_account_id', 'vendor_membership_id'])) {
                throw new LogicException('Product assignment identity is immutable.');
            }

            $wasActive = $assignment->getRawOriginal('revoked_at') === null;
            $isActive = $assignment->revoked_at === null;
            $assigneeMetadataChanged = $assignment->isDirty(['assigned_by_user_id', 'assigned_at']);

            if (
                $wasActive === $isActive
                || ($wasActive && $assigneeMetadataChanged)
                || (! $wasActive && (! $assignment->isDirty('assigned_by_user_id') || ! $assignment->isDirty('assigned_at')))
            ) {
                throw new LogicException('Product assignments may only transition between granted and revoked states.');
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
