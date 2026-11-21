<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class ApprovalRequest extends BaseModel
{
    protected $fillable = [
        'vendor_account_id',
        'target_vendor_membership_id',
        'role_id',
        'action',
        'status',
        'requested_by_user_id',
        'decided_by_user_id',
        'reason',
        'idempotency_key',
        'payload_hash',
        'expires_at',
        'decided_at',
        'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $request): void {
            if ($request->action !== 'vendor_role.assign' || $request->status !== 'pending') {
                throw new LogicException('Approval request action or initial status is invalid.');
            }
        });

        self::updating(static function (self $request): void {
            $allowed = ['status', 'decided_by_user_id', 'decided_at', 'decision_reason', 'updated_at'];
            $dirty = array_keys($request->getDirty());

            if (
                $request->getOriginal('status') !== 'pending'
                || ! in_array($request->status, ['approved', 'rejected', 'expired'], true)
                || array_diff($dirty, $allowed) !== []
            ) {
                throw new LogicException('Approval request state transitions are restricted.');
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Approval requests cannot be deleted.');
        });
    }

    public function transitionTo(string $status, ?string $actorId, string $reason): void
    {
        if ($this->status !== 'pending' || ! in_array($status, ['approved', 'rejected', 'expired'], true)) {
            throw new LogicException('Approval request transition is invalid.');
        }

        $this->forceFill([
            'status' => $status,
            'decided_by_user_id' => $actorId,
            'decided_at' => now(),
            'decision_reason' => mb_substr(trim($reason), 0, 500),
        ])->save();
    }

    /** @return BelongsTo<VendorAccount, $this> */
    public function vendorAccount(): BelongsTo
    {
        return $this->belongsTo(VendorAccount::class);
    }

    /** @return BelongsTo<VendorMembership, $this> */
    public function targetMembership(): BelongsTo
    {
        return $this->belongsTo(VendorMembership::class, 'target_vendor_membership_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** @return HasMany<ApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class);
    }
}
