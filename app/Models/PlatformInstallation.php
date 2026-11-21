<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PlatformInstallation extends BaseModel
{
    protected $fillable = [
        'installation_key',
        'bootstrapped_user_id',
        'bootstrapped_at',
    ];

    protected function casts(): array
    {
        return ['bootstrapped_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function bootstrappedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bootstrapped_user_id');
    }
}
