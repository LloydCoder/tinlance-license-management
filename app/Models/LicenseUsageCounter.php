<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $license_id
 * @property string $vendor_account_id
 * @property string $product_id
 * @property string $metric
 * @property string $period
 * @property Carbon $period_start
 * @property Carbon|null $period_end
 * @property int $quantity
 * @property int $version
 */
final class LicenseUsageCounter extends BaseModel
{
    protected $fillable = [
        'license_id',
        'vendor_account_id',
        'product_id',
        'metric',
        'period',
        'period_start',
        'period_end',
        'quantity',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'quantity' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $counter): void {
            if (
                ! in_array($counter->period, ['monthly', 'annual', 'lifetime'], true)
                || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', (string) $counter->metric) !== 1
                || (int) $counter->quantity < 1
                || (int) $counter->version !== 1
                || ($counter->period_end !== null && $counter->period_end->lessThanOrEqualTo($counter->period_start))
            ) {
                throw new LogicException('Initial licence usage counter metadata is invalid.');
            }

            $license = License::query()
                ->whereKey($counter->license_id)
                ->where('vendor_account_id', $counter->vendor_account_id)
                ->where('product_id', $counter->product_id)
                ->exists();

            if (! $license) {
                throw new LogicException('Licence usage counter scope must match its licence.');
            }
        });

        self::updating(static function (self $counter): void {
            if ($counter->isDirty([
                'license_id',
                'vendor_account_id',
                'product_id',
                'metric',
                'period',
                'period_start',
                'period_end',
            ])) {
                throw new LogicException('Licence usage counter identity is immutable.');
            }

            if (
                (int) $counter->quantity <= (int) $counter->getRawOriginal('quantity')
                || (int) $counter->version !== ((int) $counter->getRawOriginal('version') + 1)
            ) {
                throw new LogicException('Usage quantity must increase with exactly one aggregate version.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Licence usage counters must be retained.');
        });
    }

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /** @return HasMany<LicenseUsageEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(LicenseUsageEvent::class, 'counter_id');
    }
}
