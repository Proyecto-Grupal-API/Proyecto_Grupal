<?php

return [
    // Explicit compatibility fallback for existing installations. Set a dedicated
    // QR_LOOKUP_KEY before changing APP_KEY or removing this fallback.
    'lookup_key' => env('QR_LOOKUP_KEY') ?: env('APP_KEY'),
    'short_code_reuse_grace_seconds' => 30,
];
