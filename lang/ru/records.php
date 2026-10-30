<?php

// The records journal (docs/PLAN.md, D39): one line per fact. :count lines are picked by number.
return [
    'streak_milestone' => [
        'habit' => ':name: :count день подряд|:name: :count дня подряд|:name: :count дней подряд',
        'habit_week' => ':name: :count неделя подряд|:name: :count недели подряд|:name: :count недель подряд',
        'limit' => 'В лимите: :count день подряд|В лимите: :count дня подряд|В лимите: :count дней подряд',
        'records' => 'Записи: :count день подряд|Записи: :count дня подряд|Записи: :count дней подряд',
        'contributions' => 'Взносы вовремя: :count период подряд|Взносы вовремя: :count периода подряд|Взносы вовремя: :count периодов подряд',
        'prayer' => 'Намаз вовремя: :count день подряд|Намаз вовремя: :count дня подряд|Намаз вовремя: :count дней подряд',
    ],
    'streak_record' => [
        'habit' => ':name: новый рекорд, :count день подряд|:name: новый рекорд, :count дня подряд|:name: новый рекорд, :count дней подряд',
        'habit_week' => ':name: новый рекорд, :count неделя подряд|:name: новый рекорд, :count недели подряд|:name: новый рекорд, :count недель подряд',
        'limit' => 'В лимите: новый рекорд, :count день подряд|В лимите: новый рекорд, :count дня подряд|В лимите: новый рекорд, :count дней подряд',
        'records' => 'Записи: новый рекорд, :count день подряд|Записи: новый рекорд, :count дня подряд|Записи: новый рекорд, :count дней подряд',
        'contributions' => 'Взносы вовремя: новый рекорд, :count период подряд|Взносы вовремя: новый рекорд, :count периода подряд|Взносы вовремя: новый рекорд, :count периодов подряд',
        'prayer' => 'Намаз вовремя: новый рекорд, :count день подряд|Намаз вовремя: новый рекорд, :count дня подряд|Намаз вовремя: новый рекорд, :count дней подряд',
    ],
    'goal_first' => ':goal: первый взнос, :amount',
    'goal_step' => ':goal: собрано :percent%, :amount',
    'goal_step_debt' => ':goal: выплачено :percent%',
    'goal_done' => 'Цель достигнута: :goal',
    'goal_done_buy' => 'Куплено: :goal',
    'debt_closed' => 'Долг закрыт: :name',
    'cushion' => 'Подушка: :count месяц расходов|Подушка: :count месяца расходов|Подушка: :count месяцев расходов',
    'month_budget' => ':Month в бюджете: :spent из :limit',
    'savings_rate' => ':Month: отложено :percent% дохода, рекорд',
];
