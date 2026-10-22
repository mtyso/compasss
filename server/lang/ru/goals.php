<?php

// Goals (docs/PLAN.md, 2.6): names the server gives to what it creates, and its error messages.
return [
    'jar_name' => 'Копилка · :goal',
    'contribution_note' => 'В цель «:goal»',
    'purchase_note' => ':goal',
    'account_taken' => 'Этот счёт уже копилка другой цели.',
    'account_spendable' => 'Копилкой может быть только счёт, который не входит в деньги на траты.',
    'account_not_debt' => 'Для долга нужен счёт долга.',
    'account_currency' => 'Валюта цели должна совпадать с валютой её счёта.',
    'target_required' => 'Укажи сумму цели.',
    'currency_required' => 'Укажи валюту цели.',
    'no_money' => 'У этой цели нет денег.',
    'not_buy' => 'Покупка бывает только у цели «Купить».',
    'no_rate' => 'Нет курса для этой валюты. Введи сумму в валюте счёта.',
    'same_account' => 'Нельзя отложить со счёта цели в него же.',
    'installment_name' => 'Рассрочка · :goal',
    'interest_note' => 'Переплата по рассрочке: :goal',
    'price_required' => 'Укажи цену, чтобы посчитать первый взнос.',
    'loan_months_required' => 'Укажи срок рассрочки в месяцах.',
    'no_loan' => 'На рассрочку ничего не остаётся: взнос покрывает всю цену.',
];
