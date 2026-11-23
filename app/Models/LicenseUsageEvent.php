<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $counter_id
 * @property string $license_id
 * @property string $vendor_account_id
 * @property string $product_id
 * @property string $actor_user_id
 * @property string $metric
 * @property string $period
 * @property int $quantity_delta
 * @property int $resulting_quantity
 * @property Carbon $period_start
 * @property Carbon|null $period_end
 * @property string $reason
 * @property string $request_id
 * @property string $payload_sha256
 * @property array<string, mixed> $event_payload
 */
final class LicenseUsageEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'counter_id',
        'license_id',
        'vendor_account_id',
        'product_id',
        'actor_user_id',
        'metric',
        'period',
        'quantity_delta',
        'resulting_quantity',
        'period_start',
        'period_end',
        'reason',
        'request_id',
        'payload_sha256',
        'event_payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'resulting_quantity' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'event_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            if (
                (int) $event->quantity_delta < 1
                || (int) $event->resulting_quantity < (int) $event->quantity_delta
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $event->payload_sha256) !== 1
                || trim((string) $event->reason) === ''
                || trim((string) $event->request_id) === ''
            ) {
                throw new LogicException('Licence usage event metadata is invalid.');
            }

            $counter = LicenseUsageCounter::query()
                ->whereKey($event->counter_id)
                ->where('license_id', $event->license_id)
                ->where('vendor_account_id', $event->vendor_account_id)
                ->where('product_id', $event->product_id)
                ->where('metric', $event->metric)
                ->where('period', $event->period)
                ->where('period_start', $event->period_start)
                ->where('period_end', $event->period_end)
                ->where('quantity', $event->resulting_quantity)
                ->exists();

            if (! $counter) {
                throw new LogicException('Licence usage event must match its counter projection.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Licence usage events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Licence usage events are immutable.');
        });
    }

    /** @return BelongsTo<LicenseUsageCounter, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(LicenseUsageCounter::class, 'counter_id');
    }
}
