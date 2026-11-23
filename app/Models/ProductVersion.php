<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SemanticVersion;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $product_id
 * @property string $vendor_account_id
 * @property string $version
 * @property string $status
 * @property string|null $release_notes
 * @property string|null $artifact_sha256
 * @property int|null $artifact_size_bytes
 * @property int $version_number
 * @property string $created_by_user_id
 * @property string $updated_by_user_id
 * @property Carbon|null $published_at
 */
final class ProductVersion extends BaseModel
{
    protected $fillable = [
        'product_id',
        'vendor_account_id',
        'version',
        'status',
        'release_notes',
        'artifact_sha256',
        'artifact_size_bytes',
        'version_number',
        'created_by_user_id',
        'updated_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'artifact_size_bytes' => 'integer',
            'version_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $version): void {
            if (
                ! SemanticVersion::isValid((string) $version->version)
                || $version->status !== 'draft'
                || (int) $version->version_number !== 1
                || ($version->artifact_sha256 !== null && preg_match('/\A[a-f0-9]{64}\z/D', (string) $version->artifact_sha256) !== 1)
                || ($version->artifact_size_bytes !== null && (int) $version->artifact_size_bytes < 1)
            ) {
                throw new LogicException('Product version metadata is invalid.');
            }
        });

        self::updating(static function (self $version): void {
            if ($version->isDirty(['product_id', 'vendor_account_id', 'version', 'created_by_user_id'])) {
                throw new LogicException('Product version identity is immutable.');
            }

            $originalStatus = (string) $version->getRawOriginal('status');
            $originalVersionNumber = (int) $version->getRawOriginal('version_number');

            if (
                $originalStatus !== 'draft'
                || (int) $version->version_number !== $originalVersionNumber + 1
            ) {
                throw new LogicException('Only draft product versions may advance through a versioned transition.');
            }

            if ($version->status === 'published') {
                if (
                    $version->published_at === null
                    || $version->isDirty(['release_notes', 'artifact_sha256', 'artifact_size_bytes'])
                ) {
                    throw new LogicException('Publishing must freeze product version metadata.');
                }

                return;
            }

            if (
                $version->status !== 'draft'
                || $version->published_at !== null
                || ! $version->isDirty(['release_notes', 'artifact_sha256', 'artifact_size_bytes'])
            ) {
                throw new LogicException('Draft product versions require a metadata change or a publish transition.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Product versions must be retained; published versions are immutable.');
        });
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
