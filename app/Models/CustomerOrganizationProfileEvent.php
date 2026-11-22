<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property list<string> $changed_fields
 * @property string $reason
 * @property string $event_type
 * @property string $request_id
 * @property string|null $change_digest
 */
final class CustomerOrganizationProfileEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = [
        'customer_organization_id',
        'actor_user_id',
        'event_type',
        'reason',
        'changed_fields',
        'change_digest',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            if (! in_array($event->event_type, ['created', 'profile_updated'], true)) {
                throw new LogicException('Customer organization profile event type is invalid.');
            }

            if (
                trim($event->reason) === ''
                || mb_strlen($event->reason) > 500
                || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', (string) $event->request_id) !== 1
            ) {
                throw new LogicException('Customer organization profile event metadata is invalid.');
            }

            if ($event->change_digest !== null && preg_match('/\A[a-f0-9]{64}\z/D', $event->change_digest) !== 1) {
                throw new LogicException('Customer organization profile change digest is invalid.');
            }

            $allowedFields = ['legal_name', 'display_name', 'external_reference', 'billing_email'];
            foreach ($event->changed_fields as $field) {
                if (! in_array($field, $allowedFields, true)) {
                    throw new LogicException('Customer organization profile event contains an invalid field.');
                }
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Customer organization profile events are immutable.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Customer organization profile events are immutable.');
        });
    }

    /** @return BelongsTo<CustomerOrganization, $this> */
    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(CustomerOrganization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
