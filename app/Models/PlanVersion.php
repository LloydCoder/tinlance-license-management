<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $plan_id
 * @property string $product_id
 * @property string $vendor_account_id
 * @property int $version_number
 * @property string $status
 * @property string $billing_model
 * @property string $currency
 * @property int $price_minor
 * @property string|null $billing_interval
 * @property int $trial_days
 * @property int $maintenance_days
 * @property array<string, mixed> $entitlements
 * @property string|null $snapshot_sha256
 * @property string $created_by_user_id
 * @property Carbon|null $published_at
 */
final class PlanVersion extends BaseModel
{
    protected $fillable = [
        'plan_id',
        'product_id',
        'vendor_account_id',
        'version_number',
        'status',
        'billing_model',
        'currency',
        'price_minor',
        'billing_interval',
        'trial_days',
        'maintenance_days',
        'entitlements',
        'snapshot_sha256',
        'created_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'price_minor' => 'integer',
            'trial_days' => 'integer',
            'maintenance_days' => 'integer',
            'entitlements' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $version): void {
            if ($version->status !== 'draft' || (int) $version->version_number < 1) {
                throw new LogicException('Plan version lifecycle metadata is invalid.');
            }

            self::assertTenantIntegrity($version);
            self::assertDraftMetadata($version);
        });

        self::updating(static function (self $version): void {
            if (
                $version->getRawOriginal('status') !== 'draft'
                || $version->isDirty([
                    'plan_id',
                    'product_id',
                    'vendor_account_id',
                    'version_number',
                    'created_by_user_id',
                ])
            ) {
                throw new LogicException('Plan version identity is immutable.');
            }

            if ($version->status === 'published') {
                if (
                    $version->published_at === null
                    || $version->snapshot_sha256 === null
                    || $version->isDirty([
                        'billing_model',
                        'currency',
                        'price_minor',
                        'billing_interval',
                        'trial_days',
                        'maintenance_days',
                        'entitlements',
                    ])
                ) {
                    throw new LogicException('Publishing must freeze a complete plan version snapshot.');
                }

                return;
            }

            if (
                $version->status !== 'draft'
                || $version->published_at !== null
                || $version->snapshot_sha256 !== null
            ) {
                throw new LogicException('Plan version lifecycle transition is invalid.');
            }

            self::assertDraftMetadata($version);
        });

        self::deleting(static function (): never {
            throw new LogicException('Plan versions must be retained.');
        });
    }

    private static function assertTenantIntegrity(self $version): void
    {
        $plan = Plan::query()->whereKey($version->plan_id)->first();
        $product = Product::query()->whereKey($version->product_id)->first();
        $vendor = VendorAccount::query()
            ->whereKey($version->vendor_account_id)
            ->where('status', 'active')
            ->first();

        if (
            ! $plan instanceof Plan
            || ! $product instanceof Product
            || ! $vendor instanceof VendorAccount
            || (string) $plan->product_id !== (string) $product->getKey()
            || (string) $plan->vendor_account_id !== (string) $vendor->getKey()
            || (string) $product->vendor_account_id !== (string) $vendor->getKey()
        ) {
            throw new LogicException('A plan version must remain within its plan product vendor tenant.');
        }
    }

    private static function assertDraftMetadata(self $version): void
    {
        $billingModel = $version->getAttribute('billing_model');
        $currency = $version->getAttribute('currency');
        $priceMinor = $version->getAttribute('price_minor');
        $billingInterval = $version->getAttribute('billing_interval');
        $trialDays = $version->getAttribute('trial_days');
        $maintenanceDays = $version->getAttribute('maintenance_days');
        $entitlements = $version->getAttribute('entitlements');

        if (
            ! is_string($billingModel)
            || ! in_array($billingModel, ['perpetual', 'subscription', 'annual', 'trial'], true)
            || ! is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || ! is_int($priceMinor)
            || $priceMinor < 0
            || $priceMinor > 9007199254740991
            || ! is_int($trialDays)
            || $trialDays < 0
            || $trialDays > 365
            || ! is_int($maintenanceDays)
            || $maintenanceDays < 0
            || $maintenanceDays > 3650
            || ! is_array($entitlements)
        ) {
            throw new LogicException('Plan version metadata is invalid.');
        }

        if (
            ($billingModel === 'subscription' && ! in_array($billingInterval, ['month', 'year'], true))
            || ($billingModel !== 'subscription' && $billingInterval !== null)
            || ($billingModel === 'trial' && $trialDays < 1)
        ) {
            throw new LogicException('Billing interval and trial duration do not match the billing model.');
        }

        foreach ($entitlements as $key => $value) {
            if (
                ! is_string($key)
                || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $key) !== 1
                || (! is_bool($value) && ! is_int($value) && ! is_string($value))
                || (is_string($value) && mb_strlen($value) > 1000)
            ) {
                throw new LogicException('Entitlements must be typed scalar values keyed by stable identifiers.');
            }
        }
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
