<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PlatformAccessAuditEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'action',
        'resource_type',
        'resource_id',
        'tenant_vendor_account_id',
        'outcome',
        'reason_code',
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
            if (! in_array($event->outcome, ['allowed', 'denied'], true)) {
                throw new LogicException('Platform access audit outcome is invalid.');
            }

            if (! in_array($event->action, ['vendor.read', 'customer_organization.read'], true)) {
                throw new LogicException('Platform access audit action is invalid.');
            }

            $expectedResourceType = match ($event->action) {
                'vendor.read' => 'vendor_account',
                'customer_organization.read' => 'customer_organization',
            };

            if (
                $event->resource_type !== $expectedResourceType
                || ! in_array($event->reason_code, ['platform_super_admin', 'resource_inactive', 'tenant_inactive'], true)
            ) {
                throw new LogicException('Platform access audit resource metadata is invalid.');
            }

            if (
                preg_match('/\A[a-z][a-z0-9_]{0,127}\z/D', $event->resource_type) !== 1
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $event->request_id) !== 1
            ) {
                throw new LogicException('Platform access audit metadata is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Platform access audit events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Platform access audit events are immutable.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function tenantVendor(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class, 'tenant_vendor_account_id');
    }
}
