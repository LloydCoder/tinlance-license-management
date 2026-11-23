<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $assignment_id
 * @property string $product_id
 * @property string $vendor_account_id
 * @property string $actor_user_id
 * @property string $target_vendor_membership_id
 * @property string $event_type
 * @property string $reason
 * @property string $request_id
 */
final class ProductAssignmentEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'assignment_id',
        'product_id',
        'vendor_account_id',
        'actor_user_id',
        'target_vendor_membership_id',
        'event_type',
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
                ! in_array($event->event_type, ['granted', 'revoked'], true)
                || mb_strlen(trim((string) $event->reason)) < 5
                || mb_strlen((string) $event->reason) > 500
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', (string) $event->request_id) !== 1
            ) {
                throw new LogicException('Product assignment event metadata is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Product assignment events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Product assignment events are immutable.');
        });
    }

    /** @return BelongsTo<ProductAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProductAssignment::class, 'assignment_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<VendorMembership, $this> */
    public function targetMembership(): BelongsTo
    {
        return $this->belongsTo(VendorMembership::class, 'target_vendor_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
