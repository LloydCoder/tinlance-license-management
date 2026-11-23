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
            if (
                $version->status !== 'draft'
                || (int) $version->version_number < 1
                || ! in_array($version->billing_model, ['perpetual', 'subscription', 'annual', 'trial'], true)
                || preg_match('/\A[A-Z]{3}\z/D', (string) $version->currency) !== 1
            ) {
                throw new LogicException('Plan version metadata is invalid.');
            }

            if (
                $version->billing_model === 'subscription'
                && ! in_array($version->billing_interval, ['month', 'year'], true)
            ) {
                throw new LogicException('Subscription plans require a supported billing interval.');
            }

            foreach ($version->entitlements as $key => $value) {
                if (
                    preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $key) !== 1
                    || (! is_bool($value) && ! is_int($value) && ! is_string($value))
                ) {
                    throw new LogicException('Entitlements must be typed scalar values keyed by stable identifiers.');
                }
            }
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
        });

        self::deleting(static function (): never {
            throw new LogicException('Plan versions must be retained.');
        });
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
