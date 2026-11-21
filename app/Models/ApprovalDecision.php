<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ApprovalDecision extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'approval_request_id',
        'actor_user_id',
        'decision',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $decision): void {
            if (! in_array($decision->decision, ['approved', 'rejected', 'expired'], true)) {
                throw new LogicException('Approval decision is invalid.');
            }

            if ($decision->decision !== 'expired' && ! is_string($decision->actor_user_id)) {
                throw new LogicException('Human approval decisions require an actor.');
            }

            if ($decision->decision === 'expired' && $decision->actor_user_id !== null) {
                throw new LogicException('System expiry decisions cannot have a human actor.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Approval decisions are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Approval decisions are immutable.');
        });
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
