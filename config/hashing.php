<?php

declare(strict_types=1);

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),

    'bcrypt' => [
        'rounds' => min(16, max(12, (int) env('BCRYPT_ROUNDS', 12))),
        'verify' => env('HASH_VERIFY', true),
        'limit' => min(72, max(1, (int) env('BCRYPT_LIMIT', 72))),
    ],

    'argon' => [
        'memory' => min(262144, max(19456, (int) env('ARGON_MEMORY', 19456))),
        'threads' => min(4, max(1, (int) env('ARGON_THREADS', 1))),
        'time' => min(10, max(2, (int) env('ARGON_TIME', 2))),
        'verify' => env('HASH_VERIFY', true),
    ],

    'rehash_on_login' => env('HASH_REHASH_ON_LOGIN', true),
];
