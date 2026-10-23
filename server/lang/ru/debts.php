<?php

// Debts both ways (docs/PLAN.md, D29): notes on the transfers the server writes, and its error messages.
return [
    'lent_note' => 'В долг: :name',
    'borrowed_note' => 'Взял в долг: :name',
    'returned_note' => 'Вернули долг: :name',
    'paid_note' => 'Платёж по долгу: :name',
    'already_returned' => 'Этот долг уже вернули.',
    'already_paid' => 'Этот долг уже погашен.',
    'too_much' => 'Это больше долга: осталось :amount.',
    'account_wrong' => 'Выбери обычный счёт: карту, наличные или вклад.',
    'none' => 'Долгов нет: направлять некуда.',
    'roll_nothing' => 'Следующий долг уже закрыт.',
    'no_account' => 'Нет счёта, на который записать возврат.',
];
