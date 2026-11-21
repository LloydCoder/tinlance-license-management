<?php

use App\Http\Middleware\RequireSecureSetupTransport;
use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => ['web', RequireSecureSetupTransport::class],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => true,
    'home' => '/admin',
    'prefix' => '',
    'domain' => null,
    'lowercase_usernames' => true,
    'limiters' => [
        'login' => 'login',
        'passkeys' => 'passkeys',
    ],
    'paths' => [
        'login' => null,
        'logout' => null,
        'password' => [
            'request' => null,
            'reset' => null,
            'email' => null,
            'update' => null,
            'confirm' => null,
            'confirmation' => null,
        ],
        'register' => null,
        'verification' => [
            'notice' => null,
            'verify' => null,
            'send' => null,
        ],
        'user-profile-information' => [
            'update' => null,
        ],
        'user-password' => [
            'update' => null,
        ],
        'two-factor' => [
            'login' => null,
            'enable' => null,
            'confirm' => null,
            'disable' => null,
            'qr-code' => null,
            'secret-key' => null,
            'recovery-codes' => null,
        ],
        'passkey' => [
            'login-options' => null,
            'login' => null,
            'confirm-options' => null,
            'confirm' => null,
            'registration-options' => null,
            'store' => null,
            'destroy' => null,
        ],
    ],
    'redirects' => [
        'login' => '/admin',
        'logout' => '/login',
        'password-confirmation' => '/admin',
        'register' => '/login',
        'email-verification' => '/admin',
        'password-reset' => '/login',
    ],
    'passkeys' => [
        'relying_party_id' => parse_url(config('app.url'), PHP_URL_HOST),
        'allowed_origins' => [rtrim((string) config('app.url'), '/')],
        'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET'),
        'timeout' => 60000,
    ],
    'features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
        Features::passkeys([
            'confirmPassword' => true,
        ]),
    ],
];
