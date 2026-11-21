<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'customer_membership_roles');
    }
}
