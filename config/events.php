<?php

return [
    'sink_url' => env('EVENTS_SINK_URL'),
    'sink_token' => env('EVENTS_SINK_TOKEN'),
    'timeout' => (int) env('EVENTS_SINK_TIMEOUT', 10),
    'batch_size' => (int) env('EVENTS_PUBLISH_BATCH', 100),
];
