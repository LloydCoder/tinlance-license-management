<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class CustomerMembershipLifecycleEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'customer_membership_id',
        'actor_user_id',
        'from_status',
        'to_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            $fromStatus = $event->getAttribute('from_status');
            $toStatus = $event->getAttribute('to_status');
            $validTransition = ($fromStatus === 'invited' && in_array($toStatus, ['active', 'revoked'], true))
                || ($fromStatus === 'active' && $toStatus === 'revoked');

            if (
                ! $validTransition
                || ! is_string($event->getAttribute('reason'))
                || trim($event->getAttribute('reason')) === ''
                || mb_strlen($event->getAttribute('reason')) > 500
                || ! is_string($event->getAttribute('actor_user_id'))
            ) {
                throw new LogicException('Customer membership lifecycle event is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Membership lifecycle events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Membership lifecycle events are immutable.');
        });
    }

    /** @return BelongsTo<CustomerMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(CustomerMembership::class, 'customer_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
