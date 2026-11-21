<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class MfaStepUpEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'method',
        'outcome',
        'ip_address',
        'user_agent',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            if (! in_array($event->method, ['totp', 'passkey'], true)) {
                throw new LogicException('MFA step-up method is invalid.');
            }

            if (! in_array($event->outcome, ['succeeded', 'failed'], true)) {
                throw new LogicException('MFA step-up outcome is invalid.');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('MFA step-up events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('MFA step-up events are immutable.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
