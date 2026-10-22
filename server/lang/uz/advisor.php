<?php

// Advisor texts (docs/PLAN.md, 2.2 and 2.3). Draft translations, to be reviewed by the owner.
return [
    'rules' => [
        'budget_threshold' => [
            'near' => [
                'title' => ':category: byudjetning :percent%',
                'body' => 'Oy oxirigacha :left_uzs qoldi.',
            ],
            'over' => [
                'title' => ':category: byudjetdan oshib ketdi',
                'body' => "Reja :limit_uzs edi, :spent_uzs sarflandi, bu :over_uzs ko'proq.",
            ],
        ],
        'category_pace' => [
            'default' => [
                'title' => ':category: oydan tezroq sarflanmoqda',
                'body' => "Byudjetning :percent% sarflandi, oy esa :pace% o'tdi. Sig'ish uchun kuniga :per_day_uzs dan ko'p sarflamang.",
            ],
        ],
        'overspend_forecast' => [
            'default' => [
                'title' => ":category: oy oxirida rejadan ko'p chiqadi",
                'body' => "Shu sur'atda :limit_uzs reja o'rniga taxminan :forecast_uzs ketadi.",
            ],
        ],
        'large_spend' => [
            'default' => [
                'title' => 'Katta xarajat: :category',
                'body' => ":amount_uzs bu toifa uchun odatdagidan ancha ko'p (odatda :typical_uzs atrofida). Agar bu bir martalik xarid bo'lsa, belgilab qo'ying: u oy statistikasini buzmaydi.",
            ],
        ],
        'small_things' => [
            'default' => [
                'title' => "Mayda xarajatlar yig'iladi: :category",
                'body' => 'Oy davomida xaridlar: :count ta, jami :total_uzs.',
            ],
        ],
        'income_split' => [
            'goals' => [
                'title' => 'Daromad tushdi: :amount_uzs',
                'body' => "Keyingi daromadgacha majburiy to'lovlar :payments_uzs, reja bo'yicha maqsadlarga :goals_uzs. Yashash uchun :left_uzs qoladi, bu kuniga :per_day_uzs. «Ajratish»ni bosing, pul jamg'armalarga o'tadi.",
            ],
            'cushion' => [
                'title' => 'Daromad tushdi: :amount_uzs',
                'body' => "Keyingi daromadgacha majburiy to'lovlar :payments_uzs, yostiqqa :cushion_uzs ajratishni maslahat beraman. Yashash uchun :left_uzs qoladi, bu kuniga :per_day_uzs.",
            ],
            'new_cushion' => [
                'title' => 'Daromad tushdi: :amount_uzs',
                'body' => "Keyingi daromadgacha majburiy to'lovlar :payments_uzs. Xavfsizlik yostig'i hali yo'q: uning uchun alohida hisob oching va :cushion_uzs ajrating. Yashash uchun :left_uzs qoladi, bu kuniga :per_day_uzs.",
            ],
            'plain' => [
                'title' => 'Daromad tushdi: :amount_uzs',
                'body' => "Keyingi daromadgacha majburiy to'lovlar :payments_uzs. Yashash uchun :left_uzs qoladi, bu kuniga :per_day_uzs.",
            ],
        ],
        'goal_due' => [
            'first' => [
                'title' => 'Maqsadlarga hali :goals_uzs ajratilmagan',
                'body' => "Keyingi daromadgacha kunlar: :days. Bu pul kunlik limitga kirmaydi: tasodifan sarflab qo'ymaslik uchun ajratib qo'ying.",
            ],
            'leftover' => [
                'title' => 'Maqsadlarga hali :goals_uzs ajratilmagan',
                'body' => "Keyingi daromadgacha kunlar: :days. Pul qolgan bo'lsa, hozir ajrating, aks holda maqsadlar rejasi suriladi.",
            ],
            'leftover_debt' => [
                'title' => 'Maqsad va qarzlarga hali :goals_uzs kiritilmagan',
                'body' => "Keyingi daromadgacha kunlar: :days. Qarzlar bo'yicha :debt_uzs to'lov kunlik limitga kirmaydi: kechikmasligi uchun uni kiriting. Qolganini pul qolgan bo'lsa ajrating.",
            ],
        ],
        'goal_leftover' => [
            'days' => [
                'title' => 'Daromadgacha :left_uzs qoldi',
                'body' => '«:goal» maqsadiga :amount ajrating: maqsad taxminan :days kun yaqinlashadi.',
            ],
            'plain' => [
                'title' => 'Daromadgacha :left_uzs qoldi',
                'body' => 'Pul tarqab ketmasidan «:goal» maqsadiga :amount ajrating.',
            ],
        ],
        'goal_pace' => [
            'behind' => [
                'title' => '«:goal» jadvaldan :behind_amount ortda',
                'body' => "Ulgurish uchun (:deadline_on) oyiga :monthly_amount ajrating. Maqsad ekranida «Qanday tezlashtirish»ni ko'ring.",
            ],
            'late' => [
                'title' => '«:goal» maqsadining muddati o\'tdi',
                'body' => "Yana :remaining_amount yig'ish kerak. Yangi muddat qo'ying, reja qayta hisoblanadi.",
            ],
            'ahead' => [
                'title' => '«:goal»: rejadan tezroq ketyapsiz',
                'body' => "Shu sur'atda :fact_on gacha yig'asiz, muddatdan (:deadline_on) oldin.",
            ],
        ],
        'goal_reached' => [
            'save' => [
                'title' => '«:goal» maqsadi yig\'ildi!',
                'body' => ":target_amount joyida. Pul o'zingiz o'tkazmaguningizcha jamg'armada turadi.",
            ],
            'buy' => [
                'title' => '«:goal» uchun yig\'ildi!',
                'body' => "Sotib olish mumkin. Sotib olgach, maqsad ekranida «Sotib oldim»ni bosing, xaridni yozib qo'yaman.",
            ],
            'installment' => [
                'title' => '«:goal» uchun boshlang\'ich to\'lov yig\'ildi!',
                'body' => ":target_amount joyida. Sotib olgach, «Sotib oldim»ni bosing: qolgani oyiga :payment_amount to'lovli muddatli to'lovga aylanadi.",
            ],
            'debt' => [
                'title' => '«:goal» qarzi yopildi!',
                'body' => "To'lovlarga ketayotgan pulni endi keyingi maqsadga yo'naltirish mumkin.",
            ],
            'debt_roll' => [
                'title' => '«:goal» qarzi yopildi!',
                'body' => "Oyiga :payment_amount bo'shadi. Uni «:next»ga o'tkazing: u tezroq yopiladi, limit esa o'zgarmaydi.",
            ],
        ],
        'cushion' => [
            'none' => [
                'title' => "Xavfsizlik yostig'i hali yo'q",
                'body' => 'Odatdagi xarajatlarning :target_months oylik zaxirasini tavsiya qilaman, bu :target_uzs. «Yostiq» belgili alohida hisob oching va oyiga :monthly_uzs dan ajrating.',
            ],
            'below' => [
                'title' => 'Yostiq :cushion_months oyga yetadi',
                'body' => 'Maqsad: odatdagi xarajatlarning :target_months oyligi. Bir yilda yetish uchun oyiga :monthly_uzs dan ajrating.',
            ],
            'reached' => [
                'title' => 'Yostiq :cushion_months oyga yetadi',
                'body' => 'Maqsadga erishildi: zaxira odatdagi xarajatlarning :cushion_months oyiga yetadi.',
            ],
        ],
        'savings_rate' => [
            'compare' => [
                'title' => ":Month oyida daromadning :rate% jamg'arildi",
                'body' => ':Prev_month oyida :prev_rate% edi.',
            ],
            'plain' => [
                'title' => ":Month oyida daromadning :rate% jamg'arildi",
                'body' => 'Bu :saved_uzs. Har bir daromadning 10–20% i yaxshi maqsad.',
            ],
            'deficit' => [
                'title' => ":Month oyida xarajatlar daromaddan ko'p bo'ldi",
                'body' => "Farq :deficit_uzs. Qaysi moslashuvchan toifalar ko'proq o'sganini ko'ring.",
            ],
        ],
        'good_news' => [
            'budget_kept' => [
                'title' => ':Month oyi byudjeti bajarildi',
                'body' => 'Hech bir toifa rejadan oshmadi. Shunday davom eting!',
            ],
            'spending_down' => [
                'title' => ':category: :less_uzs kamroq',
                'body' => "Oyning shu kunigacha odatdagiga nisbatan. Ajoyib sur'at.",
            ],
        ],
        'forgot_to_record' => [
            'default' => [
                'title' => 'Bugun hech narsa yozilmadi',
                'body' => "Xarajatlar bo'ldimi? Esingizdaligida yozib qo'ying: shunda limit va maslahatlar aniq qoladi.",
            ],
        ],
        'payment_due' => [
            'tomorrow' => [
                'title' => "Ertaga to'lov: :name, :amount",
                'body' => "Uning puli limitdan ajratib qo'yilgan. To'laganingizda «To'landi» tugmasini bosing.",
            ],
            'tomorrow_short' => [
                'title' => 'Ertaga :name: «:account» hisobida :short_amount yetmaydi',
                'body' => "To'lov :amount, hisobda esa kamroq. To'lov o'tishi uchun pulni oldindan o'tkazing.",
            ],
            'today' => [
                'title' => "Bugun to'lov: :name, :amount",
                'body' => "Uning puli limitdan ajratib qo'yilgan. To'laganingizda «To'landi» tugmasini bosing.",
            ],
            'today_short' => [
                'title' => 'Bugun :name: «:account» hisobida :short_amount yetmaydi',
                'body' => "To'lov :amount, hisobda esa kamroq. To'lov o'tishi uchun hisobni to'ldiring.",
            ],
            'late' => [
                'title' => "«:name» to'langan deb belgilanmagan",
                'body' => "To'lov :amount, sanasi :due_on edi. Agar to'lagan bo'lsangiz, «To'landi» tugmasini bosing: limit unga pul ajratishni to'xtatadi.",
            ],
        ],
        'subscription_found' => [
            'default' => [
                'title' => "«:name» doimiy to'lovga o'xshaydi",
                'body' => "Ketma-ket :count oy, har safar :amount. Uni to'lovlarga qo'shing: limit unga pulni oldindan ajratadi, bot esa bir kun oldin eslatadi.",
            ],
        ],
        'lent_due' => [
            'today' => [
                'title' => ':name bugun :amount qaytarishga va\'da bergan',
                'body' => "Qaytarganda «Qaytardi»ni bosing: pul yana hisobda va limitda bo'ladi.",
            ],
            'late' => [
                'title' => ':name hali :amount qaytarmadi',
                'body' => "Va'da :due_on gacha edi. Eslatib qo'ying, qaytarganda «Qaytardi»ni bosing.",
            ],
        ],
    ],

    'can_afford' => [
        'verdicts' => [
            'yes' => 'Mumkin',
            'yes_but' => 'Mumkin, lekin',
            'wait' => "Kutib turgan ma'qul",
            'no' => 'Tavsiya etilmaydi',
        ],
        'reasons' => [
            'fits_today' => "Bugungi limitga sig'adi: bugunga :left_after_uzs qoladi.",
            'limit_drops' => "Qolgan kunlar (:days) uchun kunlik limit :limit_before_uzs o'rniga :limit_after_uzs bo'ladi.",
            'below_essentials' => 'Kunlik limit :limit_after_uzs gacha tushadi, majburiy xarajatlarga esa odatda kuniga :essential_uzs atrofida ketadi.',
            'budget_over' => '«:category» byudjeti :over_uzs ga oshib ketadi.',
            'short' => "Daromadgacha :shortfall_uzs yetmaydi: to'lovlarga ajratilgan puldan yoki zaxiradan olishga to'g'ri keladi.",
            'cushion_after' => 'Yostiqdan olinsa, u :cushion_months oyga yetadi.',
            'cushion_short' => 'Bunga hatto butun yostiq ham yetmaydi.',
            'until_income' => 'Daromad tushgandan keyin (:date_on) bemalol yetadi.',
            'until_month' => ':date_on dan yangi byudjet boshlanadi.',
            'goal_days' => "Bu «:goal» uchun :days kunlik jamg'arma.",
        ],
    ],
];
