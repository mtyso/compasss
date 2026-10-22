<?php

// Advisor texts (docs/PLAN.md, 2.2 and 2.3). Shared by the app and the bot. Sums, dates and months
// arrive already formatted (App\Advisor\Texts).
return [
    'rules' => [
        'budget_threshold' => [
            'near' => [
                'title' => ':category: :percent% бюджета',
                'body' => 'До конца месяца осталось :left_uzs.',
            ],
            'over' => [
                'title' => ':category: бюджет превышен',
                'body' => 'Потрачено :spent_uzs при плане :limit_uzs, это на :over_uzs больше.',
            ],
        ],
        'category_pace' => [
            'default' => [
                'title' => ':category: тратится быстрее месяца',
                'body' => 'Потрачено :percent% бюджета, а месяц прошёл на :pace%. Чтобы уложиться, трать не больше :per_day_uzs в день.',
            ],
        ],
        'overspend_forecast' => [
            'default' => [
                'title' => ':category: к концу месяца выйдет больше плана',
                'body' => 'При таком темпе уйдёт около :forecast_uzs при плане :limit_uzs.',
            ],
        ],
        'large_spend' => [
            'default' => [
                'title' => 'Крупная трата: :category',
                'body' => ':amount_uzs заметно больше обычного для этой категории (обычно около :typical_uzs). Если это разовая покупка, отметь её: она не испортит статистику месяца.',
            ],
        ],
        'small_things' => [
            'default' => [
                'title' => 'Мелочи складываются: :category',
                'body' => 'Покупок за месяц: :count, вместе :total_uzs.',
            ],
        ],
        'income_split' => [
            'cushion' => [
                'title' => 'Пришёл доход :amount_uzs',
                'body' => 'Обязательные платежи до следующего дохода :payments_uzs, в подушку советую отложить :cushion_uzs. На жизнь остаётся :left_uzs, это :per_day_uzs в день.',
            ],
            'new_cushion' => [
                'title' => 'Пришёл доход :amount_uzs',
                'body' => 'Обязательные платежи до следующего дохода :payments_uzs. Подушки безопасности пока нет: заведи для неё отдельный счёт и отложи :cushion_uzs. На жизнь остаётся :left_uzs, это :per_day_uzs в день.',
            ],
            'plain' => [
                'title' => 'Пришёл доход :amount_uzs',
                'body' => 'Обязательные платежи до следующего дохода :payments_uzs. На жизнь остаётся :left_uzs, это :per_day_uzs в день.',
            ],
        ],
        'cushion' => [
            'none' => [
                'title' => 'Подушки безопасности пока нет',
                'body' => 'Советую запас на :target_months мес. обычных расходов, это :target_uzs. Заведи отдельный счёт с пометкой «подушка» и откладывай по :monthly_uzs в месяц.',
            ],
            'below' => [
                'title' => 'Подушки хватит на :cushion_months мес.',
                'body' => 'Цель — :target_months мес. обычных расходов. Чтобы дойти до неё за год, откладывай по :monthly_uzs в месяц.',
            ],
            'reached' => [
                'title' => 'Подушка на :cushion_months мес.',
                'body' => 'Цель достигнута: запаса хватит на :cushion_months мес. обычных расходов.',
            ],
        ],
        'savings_rate' => [
            'compare' => [
                'title' => 'За :month отложено :rate% дохода',
                'body' => 'За :prev_month было :prev_rate%.',
            ],
            'plain' => [
                'title' => 'За :month отложено :rate% дохода',
                'body' => 'Это :saved_uzs. Хорошая цель — 10–20% каждого дохода.',
            ],
            'deficit' => [
                'title' => 'За :month расходы больше доходов',
                'body' => 'Разница :deficit_uzs. Посмотри, какие гибкие категории выросли сильнее всего.',
            ],
        ],
        'good_news' => [
            'budget_kept' => [
                'title' => 'Бюджет за :month выполнен',
                'body' => 'Ни одна категория не вышла за план. Так держать!',
            ],
            'spending_down' => [
                'title' => ':category: на :less_uzs меньше',
                'body' => 'Чем обычно бывает к этому дню месяца. Отличный темп.',
            ],
        ],
        'forgot_to_record' => [
            'default' => [
                'title' => 'Сегодня ничего не записано',
                'body' => 'Были траты? Запиши, пока помнишь: так лимит и советы останутся точными.',
            ],
        ],
    ],

    'can_afford' => [
        'verdicts' => [
            'yes' => 'Можно',
            'yes_but' => 'Можно, но',
            'wait' => 'Лучше подождать',
            'no' => 'Не стоит',
        ],
        'reasons' => [
            'fits_today' => 'Укладывается в сегодняшний лимит: на сегодня останется :left_after_uzs.',
            'limit_drops' => 'Дневной лимит на оставшиеся дни (:days) станет :limit_after_uzs вместо :limit_before_uzs.',
            'below_essentials' => 'Дневной лимит упадёт до :limit_after_uzs, а на обязательное обычно уходит около :essential_uzs в день.',
            'budget_over' => 'Бюджет «:category» будет превышен на :over_uzs.',
            'short' => 'До дохода не хватает :shortfall_uzs: придётся взять из денег, отложенных на платежи, или из запаса.',
            'cushion_after' => 'Если взять из подушки, её хватит на :cushion_months мес.',
            'cushion_short' => 'Даже всей подушки на это не хватит.',
            'until_income' => 'После дохода :date_on хватит без ущерба.',
            'until_month' => 'С :date_on начнётся новый бюджет.',
        ],
    ],
];
