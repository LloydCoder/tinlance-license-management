<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VendorMembership extends BaseModel
{
    protected $fillable = [
        'vendor_account_id',
        'user_id',
        'status',
        'invited_by',
        'joined_at',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<VendorMembershipRoleEvent, $this> */
    public function roleEvents(): HasMany
    {
        return $this->hasMany(VendorMembershipRoleEvent::class);
    }

    /** @return HasMany<VendorMembershipLifecycleEvent, $this> */
    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(VendorMembershipLifecycleEvent::class, 'vendor_membership_id');
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'vendor_membership_roles');
    }
}
