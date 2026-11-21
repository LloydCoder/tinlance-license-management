<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class VendorMembershipInvitation extends BaseModel
{
    protected $fillable = [
        'vendor_membership_id',
        'invited_by_user_id',
        'invited_email',
        'reason',
        'token_hash',
        'expires_at',
        'sent_at',
        'accepted_at',
        'revoked_at',
        'delivery_status',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(static function (self $invitation): void {
            foreach (['vendor_membership_id', 'invited_by_user_id', 'invited_email', 'reason', 'token_hash', 'expires_at'] as $immutable) {
                if ($invitation->isDirty($immutable)) {
                    throw new LogicException('Membership invitation identity and token fields are immutable.');
                }
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Membership invitation evidence cannot be deleted.');
        });
    }

    /** @return BelongsTo<VendorMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(VendorMembership::class, 'vendor_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
