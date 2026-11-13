<?php
return [
    // Explicit deployment activation. Never grants anything on boot.
    'enabled' => env('IDENTITY_DESIGNATIONS_ENABLED', false),
    // Offline operator-approved exact tuples. Short activation window, no shared secret.
    // actor_id, until (ISO8601), grants: [{user_id,role_key,scope_type,scope_id,ends_at}].
    'bootstrap' => json_decode((string) env('IDENTITY_DESIGNATIONS_BOOTSTRAP_JSON', '{}'), true, 512, JSON_THROW_ON_ERROR),
];
