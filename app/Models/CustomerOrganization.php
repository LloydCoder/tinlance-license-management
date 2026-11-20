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

    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class);
    }
}
