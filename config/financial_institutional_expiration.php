<?php
return [
    // This flag schedules work; it never grants budget.expire authority.
    'schedule_enabled' => env('FINANCIAL_INSTITUTIONAL_BECA_EXPIRATION_ENABLED', false),
    'actor_id' => env('FINANCIAL_INSTITUTIONAL_BECA_EXPIRATION_ACTOR'),
];
