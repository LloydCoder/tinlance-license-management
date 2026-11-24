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
    'approvals' => [
        'ttl_seconds' => max(300, (int) env('TLMS_APPROVAL_TTL_SECONDS', 86400)),
    ],
    'memberships' => [
        'invitation_ttl_hours' => max(1, min(168, (int) env('TLMS_MEMBERSHIP_INVITATION_TTL_HOURS', 72))),
    ],
    'licensing' => [
        'key_peppers_json' => env('TLMS_LICENSE_KEY_PEPPERS_JSON', ''),
        'active_pepper_key_id' => env('TLMS_LICENSE_KEY_ACTIVE_PEPPER_ID', ''),
        'response_encryption_keys_json' => env('TLMS_LICENSE_RESPONSE_ENCRYPTION_KEYS_JSON', ''),
        'active_response_encryption_key_id' => env('TLMS_LICENSE_RESPONSE_ACTIVE_KEY_ID', ''),
        'signing_private_seeds_json' => env('TLMS_LICENSE_SIGNING_PRIVATE_SEEDS_JSON', ''),
        'active_signing_key_id' => env('TLMS_LICENSE_SIGNING_ACTIVE_KEY_ID', ''),
        'issuance_secret_ttl_seconds' => max(60, min(86400, (int) env('TLMS_LICENSE_ISSUANCE_SECRET_TTL_SECONDS', 900))),
    ],
];
