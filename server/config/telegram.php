<?php

return [

    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    // Telegram echoes this in the X-Telegram-Bot-Api-Secret-Token header on every webhook call.
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    'allowed_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ALLOWED_TELEGRAM_IDS', '')),
    ))),

    // The address the Mini App opens: the same SPA the browser gets. Must be https.
    'webapp_url' => env('TELEGRAM_WEBAPP_URL', env('APP_URL')),

    // Cards go out as Bot API rich messages (10.1+). Off: plain HTML messages, same content.
    'rich_messages' => (bool) env('TELEGRAM_RICH_MESSAGES', true),

    // How old the Mini App launch data may be when it is exchanged for an API token.
    'webapp_max_age' => (int) env('TELEGRAM_WEBAPP_MAX_AGE', 86400),

    // Daily bot messages, Asia/Tashkent time (docs/BOT.md).
    'digest' => [
        'morning_at' => env('TELEGRAM_MORNING_AT', '09:00'),
        'evening_at' => env('TELEGRAM_EVENING_AT', '21:00'),
        'weekly_at' => env('TELEGRAM_WEEKLY_AT', '20:00'),
    ],

];
