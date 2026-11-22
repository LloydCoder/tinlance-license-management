<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PlatformAccessAuditEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'sequence',
        'previous_hash',
        'event_hash',
        'hash_version',
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
        return [
            'sequence' => 'integer',
            'hash_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            if (! in_array($event->outcome, ['allowed', 'denied'], true)) {
                throw new LogicException('Platform access audit outcome is invalid.');
            }

            if (! in_array($event->action, [
                'vendor.read',
                'customer_organization.read',
                'customer_organization.created',
                'customer_organization.profile_updated',
            ], true)) {
                throw new LogicException('Platform access audit action is invalid.');
            }

            $expectedResourceType = match ($event->action) {
                'vendor.read' => 'vendor_account',
                'customer_organization.read',
                'customer_organization.created',
                'customer_organization.profile_updated' => 'customer_organization',
            };

            $allowedReasons = match ($event->action) {
                'vendor.read', 'customer_organization.read' => [
                    'platform_super_admin',
                    'actor_not_platform_super_admin',
                    'resource_inactive',
                    'tenant_inactive',
                ],
                'customer_organization.created' => ['vendor_customer_management'],
                'customer_organization.profile_updated' => [
                    'vendor_customer_management',
                    'customer_owner_profile_management',
                ],
            };

            $mutationAction = in_array($event->action, [
                'customer_organization.created',
                'customer_organization.profile_updated',
            ], true);

            if (
                $event->resource_type !== $expectedResourceType
                || ! in_array($event->reason_code, $allowedReasons, true)
                || ($mutationAction && $event->outcome !== 'allowed')
            ) {
                throw new LogicException('Platform access audit resource metadata is invalid.');
            }

            if (
                preg_match('/\A[a-z][a-z0-9_]{0,127}\z/D', $event->resource_type) !== 1
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $event->request_id) !== 1
            ) {
                throw new LogicException('Platform access audit metadata is invalid.');
            }

            $previousHashInvalid = (int) $event->sequence === 1
                ? $event->previous_hash !== null
                : ! is_string($event->previous_hash)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $event->previous_hash) !== 1;

            if (
                (int) $event->sequence < 1
                || (int) $event->hash_version !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $event->event_hash) !== 1
                || $previousHashInvalid
            ) {
                throw new LogicException('Platform access audit chain metadata is invalid.');
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
