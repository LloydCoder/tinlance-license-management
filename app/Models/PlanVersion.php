<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PlanVersion extends BaseModel
{
    protected $fillable = ['plan_id','product_id','vendor_account_id','version_number','status','billing_model','currency','price_minor','billing_interval','trial_days','maintenance_days','entitlements','snapshot_sha256','created_by_user_id','published_at'];

    protected function casts(): array
    {
        return ['version_number'=>'integer','price_minor'=>'integer','trial_days'=>'integer','maintenance_days'=>'integer','entitlements'=>'array','published_at'=>'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $version): void {
            if ($version->status !== 'draft' || (int) $version->version_number < 1
                || ! in_array($version->billing_model, ['perpetual','subscription','annual','trial'], true)
                || preg_match('/\A[A-Z]{3}\z/D', (string) $version->currency) !== 1
                || (int) $version->price_minor < 0
                || ! is_array($version->entitlements)) {
                throw new LogicException('Plan version metadata is invalid.');
            }
            if ($version->billing_model === 'subscription' && ! in_array($version->billing_interval, ['month','year'], true)) {
                throw new LogicException('Subscription plans require a supported billing interval.');
            }
            foreach ($version->entitlements as $key => $value) {
                if (! is_string($key) || preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $key) !== 1
                    || (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null)) {
                    throw new LogicException('Entitlements must be typed scalar values keyed by stable identifiers.');
                }
            }
        });

        self::updating(static function (self $version): void {
            if ($version->getRawOriginal('status') !== 'draft'
                || $version->isDirty(['plan_id','product_id','vendor_account_id','version_number','billing_model','currency','price_minor','billing_interval','trial_days','maintenance_days','entitlements','created_by_user_id'])) {
                throw new LogicException('Plan versions are immutable after publication.');
            }
            if ($version->status !== 'published' || $version->published_at === null || $version->snapshot_sha256 === null) {
                throw new LogicException('Only a complete publish transition is allowed.');
            }
        });

        self::deleting(static function (): never { throw new LogicException('Plan versions must be retained.'); });
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
}
