<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class VendorAccount extends BaseModel
{
    protected $fillable = [
        'installation_key',
        'legal_name',
        'display_name',
        'status',
        'default_currency',
        'timezone',
    ];

    /** @return HasMany<VendorMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(VendorMembership::class);
    }

    /** @return HasMany<CustomerOrganization, $this> */
    public function customerOrganizations(): HasMany
    {
        return $this->hasMany(CustomerOrganization::class);
    }
}
