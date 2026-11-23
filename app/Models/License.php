<?php

declare(strict_types=1);

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;
use Illuminate\\Support\\Carbon;
use LogicException;
use Tlms\\Domain\\Licensing\\LicenseLifecycle;

/**
 * @property string $vendor_account_id
 * @property string $customer_organization_id
 * @property string $product_id
 * @property string $plan_version_id
 * @property string $public_reference
 * @property string $key_verifier
 * @property string $pepper_key_id
 * @property string $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $grace_until
 * @property int $version
 */
final class License extends BaseModel
{
    protected $fillable = [
        'vendor_account_id',
        'customer_organization_id',
        'product_id',
        'plan_version_id',
        'public_reference',
        'key_verifier',
        'pepper_key_id',
        'status',
        'starts_at',
        'expires_at',
        'grace_until',
        'version',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'grace_until' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $license): void {
            if (
                $license->status !== LicenseLifecycle::ISSUED
                || (int) $license->version !== 1
                || preg_match('/\\A[a-f0-9]{64}\\z/D', (string) $license->key_verifier) !== 1
                || preg_match('/\\A[A-Za-z][A-Za-z0-9._-]{0,63}\\z/D', (string) $license->pepper_key_id) !== 1
                || trim((string) $license->public_reference) === ''
            ) {
                throw new LogicException('Initial licence metadata is invalid.');
            }

            $customer = CustomerOrganization::query()
                ->whereKey($license->customer_organization_id)
                ->where('vendor_account_id', $license->vendor_account_id)
                ->where('status', 'active')
                ->first();
            $product = Product::query()
                ->whereKey($license->product_id)
                ->where('vendor_account_id', $license->vendor_account_id)
                ->where('status', 'active')
                ->first();
            $version = PlanVersion::query()
                ->whereKey($license->plan_version_id)
                ->where('product_id', $license->product_id)
                ->where('vendor_account_id', $license->vendor_account_id)
                ->where('status', 'published')
                ->first();

            if (! $customer instanceof CustomerOrganization
                || ! $product instanceof Product
                || ! $version instanceof PlanVersion) {
                throw new LogicException('A licence must reference an active customer, active product and published plan version in one vendor tenant.');
            }

            if ($license->expires_at !== null && $license->starts_at !== null
                && $license->expires_at->lessThanOrEqualTo($license->starts_at)) {
                throw new LogicException('Licence expiry must be later than its start time.');
            }

            if ($license->grace_until !== null && $license->expires_at === null) {
                throw new LogicException('A grace deadline requires a licence expiry.');
            }

            if ($license->grace_until !== null && $license->grace_until->lessThan($license->expires_at)) {
                throw new LogicException('A grace deadline cannot precede licence expiry.');
            }
        });

        self::updating(static function (self $license): void {
            if ($license->isDirty([
                'vendor_account_id',
                'customer_organization_id',
                'product_id',
                'plan_version_id',
                'public_reference',
                'key_verifier',
                'pepper_key_id',
                'created_by_user_id',
            ])) {
                throw new LogicException('Licence identity and credential metadata are service-controlled.');
            }

            if ($license->isDirty('status')) {
                LicenseLifecycle::assertTransition(
                    (string) $license->getRawOriginal('status'),
                    (string) $license->status,
                );
            }

            if ((int) $license->version !== ((int) $license->getRawOriginal('version') + 1)) {
                throw new LogicException('Every licence mutation must advance its aggregate version exactly once.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Licences must be retained and transitioned, not deleted.');
        });
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return BelongsTo<CustomerOrganization, $this> */
    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(CustomerOrganization::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<PlanVersion, $this> */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    /** @return HasMany<LicenseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(LicenseEvent::class);
    }
}
