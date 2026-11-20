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

    public function memberships(): HasMany
    {
        return $this->hasMany(VendorMembership::class);
    }

    public function customerOrganizations(): HasMany
    {
        return $this->hasMany(CustomerOrganization::class);
    }
}
