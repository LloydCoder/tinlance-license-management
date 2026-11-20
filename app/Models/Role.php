<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends BaseModel
{
    protected $fillable = [
        'scope',
        'slug',
        'name',
        'is_system',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function vendorMemberships(): BelongsToMany
    {
        return $this->belongsToMany(VendorMembership::class, 'vendor_membership_roles');
    }

    public function customerMemberships(): BelongsToMany
    {
        return $this->belongsToMany(CustomerMembership::class, 'customer_membership_roles');
    }
}
