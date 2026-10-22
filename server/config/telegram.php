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

];
