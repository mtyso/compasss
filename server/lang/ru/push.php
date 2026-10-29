<?php

// Push notifications on the phone (docs/PLAN.md, D36). Advice, reminders and the digests' titles reuse the
// advisor's and the bot's texts, so the phone and the chat say the same.
return [
    'morning_payments' => 'Сегодня платить: :names',
    'morning_tasks' => ':count дело на сегодня|:count дела на сегодня|:count дел на сегодня',
    'morning_advice' => 'Совет: :title',
    'morning_more' => 'ещё :count совет|ещё :count совета|ещё :count советов',
    'morning_free' => 'Свободно до дохода: :amount',
    'test_title' => 'Уведомления работают',
    'test_body' => 'Так будут приходить напоминания, советы и итоги.',
    'not_configured' => 'На сервере ещё не настроен Firebase, поэтому уведомления пока приходят только в Telegram.',
    'no_device' => 'Этот телефон не подключён к уведомлениям. Выключи и снова включи их.',
    'failed' => 'Firebase не принял уведомление. Попробуй чуть позже.',
];
