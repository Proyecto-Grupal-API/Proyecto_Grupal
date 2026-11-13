<?php

return [
    // Opt-in adapter; existing external Bearer contract remains the default.
    'transport' => env('EVENTS_TRANSPORT', 'legacy'),
    'platform_secret' => env('EVENTS_PLATFORM_SECRET'),
    'sink_url' => env('EVENTS_SINK_URL'),
    'sink_token' => env('EVENTS_SINK_TOKEN'),
    'timeout' => (int) env('EVENTS_SINK_TIMEOUT', 10),
    'batch_size' => (int) env('EVENTS_PUBLISH_BATCH', 100),
    'claim_lease_seconds' => (int) env('EVENTS_CLAIM_LEASE_SECONDS', 60),
];
