<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CustomerOrganization extends BaseModel
{
    protected $fillable = [
        'vendor_account_id',
        'external_reference',
        'legal_name',
        'display_name',
        'status',
        'billing_email',
    ];

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return HasMany<CustomerMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class);
    }
}
