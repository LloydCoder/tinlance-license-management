<?php

declare(strict_types=1);

return [
    'expected_db_connection' => env('TLMS_EXPECTED_DB_CONNECTION', 'sqlite'),
    'expected_db_vendor' => env('TLMS_EXPECTED_DB_VENDOR', 'mysql'),
    'expected_document_root' => env('TLMS_EXPECTED_DOCUMENT_ROOT'),
    'bootstrap_token' => env('TLMS_BOOTSTRAP_TOKEN'),
    'vendor_legal_name' => env('TLMS_VENDOR_LEGAL_NAME', 'Tinlance Limited'),
    'vendor_display_name' => env('TLMS_VENDOR_DISPLAY_NAME', 'Tinlance'),
    'security' => [
        'step_up_ttl_seconds' => max(1, (int) env('TLMS_STEP_UP_TTL_SECONDS', 300)),
    ],
];
