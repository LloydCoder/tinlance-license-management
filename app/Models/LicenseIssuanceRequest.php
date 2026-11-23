<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $vendor_account_id
 * @property string $actor_user_id
 * @property string $operation
 * @property string $idempotency_key_sha256
 * @property string $request_fingerprint_sha256
 * @property string $status
 * @property string|null $license_id
 * @property string|null $response_ciphertext
 * @property Carbon|null $expires_at
 */
final class LicenseIssuanceRequest extends BaseModel
{
    protected $fillable = [
        'vendor_account_id',
        'actor_user_id',
        'operation',
        'idempotency_key_sha256',
        'request_fingerprint_sha256',
        'status',
        'license_id',
        'response_ciphertext',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $request): void {
            if (
                trim((string) $request->operation) === ''
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $request->idempotency_key_sha256) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', (string) $request->request_fingerprint_sha256) !== 1
                || $request->status !== 'processing'
                || $request->license_id !== null
                || $request->response_ciphertext !== null
                || $request->expires_at === null
            ) {
                throw new LogicException('Licence issuance request metadata is invalid.');
            }
        });

        self::updating(static function (self $request): void {
            if ($request->isDirty([
                'vendor_account_id',
                'actor_user_id',
                'operation',
                'idempotency_key_sha256',
                'request_fingerprint_sha256',
                'expires_at',
            ])) {
                throw new LogicException('Licence issuance request identity is immutable.');
            }

            $from = (string) $request->getRawOriginal('status');
            $to = (string) $request->status;
            if ($from !== 'processing' || $to !== 'completed'
                || trim((string) $request->license_id) === ''
                || trim((string) $request->response_ciphertext) === '') {
                throw new LogicException('Licence issuance request completion is invalid.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Licence issuance request records must expire through retention controls.');
        });
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<License, $this> */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
