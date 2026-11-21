<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\RecordPasskeyStepUp;
use App\Listeners\RecordSuccessfulLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Events\PasskeyVerified;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(Login::class, RecordSuccessfulLogin::class);
        Event::listen(PasskeyVerified::class, RecordPasskeyStepUp::class);
    }
}
