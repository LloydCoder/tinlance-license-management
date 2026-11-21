<?php

declare(strict_types=1);

return [
    'expected_db_connection' => env('TLMS_EXPECTED_DB_CONNECTION', 'sqlite'),
    'expected_db_vendor' => env('TLMS_EXPECTED_DB_VENDOR', 'mysql'),
];
