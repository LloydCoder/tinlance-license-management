<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class VendorMembershipRoleEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'vendor_membership_id',
        'role_id',
        'actor_user_id',
        'action',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Membership role events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Membership role events are immutable.');
        });
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(VendorMembership::class, 'vendor_membership_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
