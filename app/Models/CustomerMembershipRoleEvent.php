<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class CustomerMembershipRoleEvent extends BaseModel
{
    public $timestamps = false;

    protected $attributes = ['actor_type' => 'user'];

    protected $fillable = [
        'customer_membership_id',
        'role_id',
        'actor_user_id',
        'actor_type',
        'action',
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
            $actorType = $event->actor_type;

            if (
                ! in_array($actorType, ['user', 'system'], true)
                || ($actorType === 'user' && $event->actor_user_id === null)
                || ($actorType === 'system' && $event->actor_user_id !== null)
            ) {
                throw new LogicException('Membership role event actor attribution is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Membership role events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Membership role events are immutable.');
        });
    }

    /** @return BelongsTo<CustomerMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(CustomerMembership::class, 'customer_membership_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
