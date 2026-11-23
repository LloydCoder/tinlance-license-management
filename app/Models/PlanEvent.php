<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $plan_id
 * @property string|null $plan_version_id
 * @property string $product_id
 * @property string $vendor_account_id
 * @property string $actor_user_id
 * @property string $event_type
 * @property string $reason
 * @property string $request_id
 * @property array<string, mixed> $event_payload
 */
final class PlanEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'plan_id',
        'plan_version_id',
        'product_id',
        'vendor_account_id',
        'actor_user_id',
        'event_type',
        'reason',
        'request_id',
        'event_payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            if (
                ! in_array($event->event_type, ['created', 'version_created', 'version_updated', 'version_published'], true)
                || mb_strlen(trim((string) $event->reason)) < 5
                || mb_strlen((string) $event->reason) > 500
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', (string) $event->request_id) !== 1
            ) {
                throw new LogicException('Plan event metadata is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Plan events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Plan events are immutable.');
        });
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }
}
