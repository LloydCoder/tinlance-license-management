<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

final class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    use HasUlids;
    use MustVerifyEmailTrait;
    use Notifiable;
    use PasskeyAuthenticatable;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'session_version' => 'integer',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (User $user): void {
            $user->email = Str::lower(trim($user->email));
        });
    }

    /**
     * Fortify calls this after the custom challenge request has atomically consumed
     * the hashed code and issued its replacement. The legacy encrypted-code mutation
     * must never run because the users column intentionally contains only an empty list.
     */
    public function replaceRecoveryCode($code): void
    {
        // Consumption and replacement are completed by MfaRecoveryCodeVault.
    }

    /** @return HasMany<VendorMembership, $this> */
    public function vendorMemberships(): HasMany
    {
        return $this->hasMany(VendorMembership::class);
    }

    /** @return HasMany<CustomerMembership, $this> */
    public function customerMemberships(): HasMany
    {
        return $this->hasMany(CustomerMembership::class);
    }
}
