<?php

declare(strict_types=1);

namespace App\Models;

use LogicException;

final class PlanEvent extends BaseModel
{
    public $timestamps = false;

    protected $fillable = ['plan_id','plan_version_id','product_id','vendor_account_id','actor_user_id','event_type','reason','request_id','event_payload'];

    protected function casts(): array { return ['event_payload'=>'array','created_at'=>'datetime']; }

    protected static function booted(): void
    {
        self::updating(static function (): never { throw new LogicException('Plan events are immutable.'); });
        self::deleting(static function (): never { throw new LogicException('Plan events are immutable.'); });
    }
}
