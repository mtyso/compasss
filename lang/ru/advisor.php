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
            'goals' => [
                'title' => 'Пришёл доход :amount_uzs',
                'body' => 'Обязательные платежи до следующего дохода :payments_uzs, в цели по плану :goals_uzs. На жизнь остаётся :left_uzs, это :per_day_uzs в день. Нажми «Отложить», и деньги уйдут в копилки.',
            ],
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
        'goal_due' => [
            'first' => [
                'title' => 'В цели ещё не отложено :goals_uzs',
                'body' => 'До следующего дохода дней: :days. Эти деньги уже не входят в дневной лимит: отложи их, чтобы случайно не потратить.',
            ],
            'leftover' => [
                'title' => 'В цели ещё не отложено :goals_uzs',
                'body' => 'До следующего дохода дней: :days. Если деньги остались, отложи их сейчас, иначе план целей сдвинется.',
            ],
        ],
        'goal_leftover' => [
            'days' => [
                'title' => 'До дохода остаётся :left_uzs',
                'body' => 'Отложи :amount в «:goal»: цель станет ближе примерно на :days дн.',
            ],
            'plain' => [
                'title' => 'До дохода остаётся :left_uzs',
                'body' => 'Отложи :amount в «:goal», пока деньги не разошлись.',
            ],
        ],
        'goal_pace' => [
            'behind' => [
                'title' => '«:goal» отстаёт от графика на :behind_amount',
                'body' => 'Чтобы успеть к :deadline_on, откладывай :monthly_amount в месяц. Посмотри «Как быстрее» на экране цели.',
            ],
            'late' => [
                'title' => 'Срок цели «:goal» прошёл',
                'body' => 'Осталось собрать :remaining_amount. Поставь новый срок, и план пересчитается.',
            ],
            'ahead' => [
                'title' => '«:goal»: идёшь быстрее плана',
                'body' => 'В таком темпе соберёшь к :fact_on, раньше срока :deadline_on.',
            ],
        ],
        'goal_reached' => [
            'save' => [
                'title' => 'Цель «:goal» собрана!',
                'body' => ':target_amount на месте. Деньги лежат в копилке, пока ты сам их не переведёшь.',
            ],
            'buy' => [
                'title' => 'На «:goal» собрано!',
                'body' => 'Можно покупать. Когда купишь, нажми «Купил» на экране цели, и я запишу покупку.',
            ],
            'installment' => [
                'title' => 'Первый взнос на «:goal» собран!',
                'body' => ':target_amount на месте. Когда купишь, нажми «Купил»: остаток станет рассрочкой с платежом :payment_amount в месяц.',
            ],
            'debt' => [
                'title' => 'Долг «:goal» закрыт!',
                'body' => 'Деньги, которые уходили на платежи, теперь можно направить в следующую цель.',
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
        'payment_due' => [
            'tomorrow' => [
                'title' => 'Завтра платёж: :name, :amount',
                'body' => 'Деньги на него уже отложены из лимита. Заплатишь — нажми «Оплачено».',
            ],
            'tomorrow_short' => [
                'title' => 'Завтра :name: на «:account» не хватает :short_amount',
                'body' => 'Платёж :amount, а на счёте меньше. Переведи деньги заранее, чтобы оплата прошла.',
            ],
            'today' => [
                'title' => 'Сегодня платёж: :name, :amount',
                'body' => 'Деньги на него отложены из лимита. Заплатишь — нажми «Оплачено».',
            ],
            'today_short' => [
                'title' => 'Сегодня :name: на «:account» не хватает :short_amount',
                'body' => 'Платёж :amount, а на счёте меньше. Пополни счёт, чтобы оплата прошла.',
            ],
            'late' => [
                'title' => '«:name» не отмечен оплаченным',
                'body' => 'Платёж :amount был :due_on. Если уже заплатил, нажми «Оплачено»: лимит перестанет откладывать на него деньги.',
            ],
        ],
        'subscription_found' => [
            'default' => [
                'title' => '«:name» похоже на регулярный платёж',
                'body' => 'Уже :count мес. подряд по :amount. Добавь его в платежи: лимит заранее отложит на него деньги, а бот напомнит накануне.',
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
            'goal_days' => 'Это :days дн. накоплений на «:goal».',
        ],
    ],
];
