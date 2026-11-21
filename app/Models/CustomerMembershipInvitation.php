<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class CustomerMembershipInvitation extends BaseModel
{
    protected $fillable = [
        'customer_membership_id',
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
            foreach (['customer_membership_id', 'invited_by_user_id', 'invited_email', 'reason', 'token_hash', 'expires_at'] as $immutable) {
                if ($invitation->isDirty($immutable)) {
                    throw new LogicException('Membership invitation identity and token fields are immutable.');
                }
            }
        });

        self::deleting(static function (): never {
            throw new LogicException('Membership invitation evidence cannot be deleted.');
        });
    }

    /** @return BelongsTo<CustomerMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(CustomerMembership::class, 'customer_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
