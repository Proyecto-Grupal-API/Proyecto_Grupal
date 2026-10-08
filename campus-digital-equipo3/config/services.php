<?php

return [
    'postmark' => ['token' => env('POSTMARK_TOKEN')],
    'resend' => ['key' => env('RESEND_KEY')],
    'ses' => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
    'slack' => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],
    'team2' => ['base_url' => env('TEAM2_BASE_URL'), 'token' => env('TEAM2_API_TOKEN')],
    'team4' => ['base_url' => env('TEAM4_BASE_URL'), 'token' => env('TEAM4_API_TOKEN')],
    'team7' => ['base_url' => env('TEAM7_BASE_URL'), 'token' => env('TEAM7_API_TOKEN')],
];