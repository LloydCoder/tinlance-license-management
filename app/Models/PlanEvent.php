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
            self::assertTenantIntegrity($event);

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

    private static function assertTenantIntegrity(self $event): void
    {
        $plan = Plan::query()->whereKey($event->plan_id)->first();
        $product = Product::query()->whereKey($event->product_id)->first();
        $vendor = VendorAccount::query()->whereKey($event->vendor_account_id)->first();
        $actorMembershipExists = VendorMembership::query()
            ->where('vendor_account_id', $event->vendor_account_id)
            ->where('user_id', $event->actor_user_id)
            ->where('status', 'active')
            ->exists();

        if (
            ($event->event_type === 'created' && $event->plan_version_id !== null)
            || ($event->event_type !== 'created' && $event->plan_version_id === null)
            || ! $actorMembershipExists
            || 
            ! $plan instanceof Plan
            || ! $product instanceof Product
            || ! $vendor instanceof VendorAccount
            || (string) $plan->product_id !== (string) $product->getKey()
            || (string) $plan->vendor_account_id !== (string) $vendor->getKey()
            || (string) $product->vendor_account_id !== (string) $vendor->getKey()
        ) {
            throw new LogicException('Plan event references must remain within one vendor tenant.');
        }

        if ($event->plan_version_id !== null) {
            $version = PlanVersion::query()->whereKey($event->plan_version_id)->first();
            if (
                ! $version instanceof PlanVersion
                || (string) $version->plan_id !== (string) $plan->getKey()
                || (string) $version->product_id !== (string) $product->getKey()
                || (string) $version->vendor_account_id !== (string) $vendor->getKey()
            ) {
                throw new LogicException('Plan event version references must match the parent plan tenant.');
            }
        }
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
