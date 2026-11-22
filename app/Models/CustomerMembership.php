<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class CustomerMembership extends BaseModel
{
    protected $fillable = [
        'customer_organization_id',
        'user_id',
        'status',
        'invited_by',
        'joined_at',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /** @return BelongsTo<CustomerOrganization, $this> */
    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(CustomerOrganization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CustomerMembershipRoleEvent, $this> */
    public function roleEvents(): HasMany
    {
        return $this->hasMany(CustomerMembershipRoleEvent::class);
    }

    /** @return HasMany<CustomerMembershipLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(CustomerMembershipLifecycleEvent::class, 'customer_membership_id');
    }

    /** @return HasOne<CustomerMembershipLifecycleEvent, $this> */
    public function latestLifecycleEvent(): HasOne
    {
        return $this->hasOne(CustomerMembershipLifecycleEvent::class, 'customer_membership_id')->latestOfMany('created_at');
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'customer_membership_roles');
    }
}
