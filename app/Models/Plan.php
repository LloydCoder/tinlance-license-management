<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class Plan extends BaseModel
{
    protected $fillable = ['product_id', 'vendor_account_id', 'code', 'name', 'description', 'status', 'version', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $plan): void {
            self::assertTenantIntegrity($plan);

            if ($plan->status !== 'draft' || (int) $plan->version !== 1
                || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', (string) $plan->code) !== 1
                || mb_strlen((string) $plan->name) < 2 || mb_strlen((string) $plan->name) > 160
                || ($plan->description !== null && mb_strlen((string) $plan->description) > 2000)) {
                throw new LogicException('Plan metadata is invalid.');
            }
        });

        self::updating(static function (self $plan): void {
            $originalStatus = $plan->getOriginal('status');
            $statusTransitionAllowed = $originalStatus === 'draft'
                && $plan->status === 'active'
                && $plan->versions()->where('status', 'published')->exists();

            if (
                $plan->isDirty(['product_id', 'vendor_account_id', 'created_by_user_id', 'code'])
                || ! in_array($originalStatus, ['draft', 'active'], true)
                || ($plan->isDirty('status') && ! $statusTransitionAllowed)
                || (int) $plan->version !== ((int) $plan->getOriginal('version') + 1)
            ) {
                throw new LogicException('Plan identity and lifecycle are service-controlled.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Plans must be retained.');
        });
    }

    private static function assertTenantIntegrity(self $plan): void
    {
        $product = Product::query()->whereKey($plan->product_id)->first();
        $vendor = VendorAccount::query()
            ->whereKey($plan->vendor_account_id)
            ->where('status', 'active')
            ->first();

        if (
            ! $product instanceof Product
            || ! $vendor instanceof VendorAccount
            || (string) $product->vendor_account_id !== (string) $vendor->getKey()
        ) {
            throw new LogicException('A plan must remain within its product vendor tenant.');
        }
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

    /** @return HasMany<PlanVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }
}
