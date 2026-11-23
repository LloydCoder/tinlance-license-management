<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property string $license_id
 * @property string $vendor_account_id
 * @property string $product_id
 * @property string $allocation_type
 * @property string $subject_hash
 * @property string $fingerprint_key_id
 * @property string $status
 * @property int $version
 */
final class LicenseAllocation extends BaseModel
{
    protected $fillable = [
        'license_id',
        'vendor_account_id',
        'product_id',
        'allocation_type',
        'subject_hash',
        'fingerprint_key_id',
        'status',
        'version',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $allocation): void {
            if (
                $allocation->status !== 'active'
                || (int) $allocation->version !== 1
                || ! in_array($allocation->allocation_type, ['site', 'seat', 'user'], true)
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $allocation->subject_hash) !== 1
                || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', (string) $allocation->fingerprint_key_id) !== 1
            ) {
                throw new LogicException('Initial licence allocation metadata is invalid.');
            }

            $license = License::query()
                ->whereKey($allocation->license_id)
                ->where('vendor_account_id', $allocation->vendor_account_id)
                ->where('product_id', $allocation->product_id)
                ->where('status', 'active')
                ->exists();

            if (! $license) {
                throw new LogicException('Licence allocations require a matching active licence.');
            }
        });

        self::updating(static function (self $allocation): void {
            if ($allocation->isDirty([
                'license_id',
                'vendor_account_id',
                'product_id',
                'allocation_type',
                'subject_hash',
                'fingerprint_key_id',
                'created_by_user_id',
            ])) {
                throw new LogicException('Licence allocation identity is immutable.');
            }

            $from = (string) $allocation->getRawOriginal('status');
            $to = (string) $allocation->status;
            if (! in_array([$from, $to], [['active', 'released'], ['released', 'active']], true)) {
                throw new LogicException('Licence allocation transition is invalid.');
            }

            if ((int) $allocation->version !== ((int) $allocation->getRawOriginal('version') + 1)) {
                throw new LogicException('Every licence allocation mutation must advance its version exactly once.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Licence allocations must be released, not deleted.');
        });
    }

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
