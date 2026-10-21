<?php

return [
    'private' => 'Bu shaxsiy bot, unga kirish yopiq.',
    'today_word' => 'bugun',
    'yesterday_word' => 'kecha',
    'days' => ':count kun',
    'weekdays' => ['Yakshanba', 'Dushanba', 'Seshanba', 'Chorshanba', 'Payshanba', 'Juma', 'Shanba'],

    'start' => [
        'title' => 'Kompas aloqada',
        'lead' => "Xarajatlarni shu yerga bitta ibora bilan yozing. Summa, valyuta va toifani o'zim aniqlayman.",
        'examples' => ['taksi 32k', 'tushlik 85 000 naqd', '$20 obuna', '+12m maosh', 'quloqchin 600k olsam bo\'ladimi?'],
        'commands' => '/today — bugun qancha sarflash mumkin · /month — oy xarajatlari',
        'footer' => 'Qolgan hammasi ilovada: yozish maydonining chap tomonidagi «Kompas» tugmasi.',
    ],

    'buttons' => [
        'open_app' => 'Kompasni ochish',
        'category' => 'Toifa',
        'account' => 'Hisob',
        'undo' => 'Bekor qilish',
        'restore' => 'Qaytarish',
        'back' => '‹ Orqaga',
        'whole' => '«:name» butunlay',
        'paid' => "To'landi: :name",
        'add_account' => "Hisob qo'shish",
        'today' => 'Bugun',
        'bought' => 'Oldim, yozish',
        'one_off' => 'Bir martalik xarid',
        'useful' => 'Foydali',
        'mute' => 'Bunday yubormang',
    ],

    'record' => [
        'title' => ':amount · :what',
        'converted' => "≈ :uzs MB kursi bo'yicha",
        'left_today' => 'Bugun yana <b>:left</b> sarflash mumkin, jami :limit.',
        'over_today' => 'Bugungi limitdan <b>:over</b> oshib ketdi.',
        'tomorrow' => 'Ertaga kuniga :amount bo\'ladi.',
        'limit_now' => 'Bugungi limit endi <b>:limit</b>.',
        'until' => ':dategacha — :days.',
        'until_month' => 'Oy oxirigacha — :days.',
        'undone' => "Bekor qilindi. Yozuv Kompasdan o'chirildi.",
        'source' => 'Telegramdan yozildi',
        'no_accounts' => "Avval ilovada hisob qo'shing, keyin xarajatlarni yoza olaman.",
        'no_rate' => "Bugungi dollar kursi yo'q, so'mga o'tkaza olmayman. Bu xarajatni ilovada kursini ko'rsatib yozing.",
        'not_understood' => 'Summani topmadim. Masalan, <code>taksi 32k</code> yoki <code>tushlik 85 000</code> deb yozing.',
        'pick_category' => 'Toifani tanlang',
        'pick_account' => 'Hisobni tanlang',
        'gone' => "Bu yozuv endi yo'q.",
        'saved' => 'Tayyor',
        'one_off' => 'bir martalik',
    ],

    'question' => [
        'what' => ':what — :amount',
        'what_plain' => ':amountlik xarid',
        'footer' => "Olsangiz, «Oldim»ni bosing — xarajatni yozib qo'yaman.",
        'expired' => 'Savol eskirgan. Xaridni oddiy xarajat kabi yozing, masalan «quloqchin 600k».',
    ],

    'insight' => [
        'record_hint' => "Shu yerga bitta ibora bilan yozing, masalan <code>tushlik 45k</code>, men hammasini yozib qo'yaman.",
        'thanks' => 'Rahmat, hisobga olaman',
        'muted' => 'Bunday maslahatlar endi kelmaydi',
        'one_off_done' => 'Bir martalik deb belgilandi',
    ],

    'today' => [
        'title_left' => 'Bugun :amount sarflash mumkin',
        'title_over' => 'Limitdan :amount oshdi',
        'spent' => 'Kunlik limit :limit · sarflandi :spent',
        'free' => "Daromadgacha bo'sh",
        'until' => 'Keyingi daromad',
        'until_month' => 'Oy oxirigacha',
        'until_value' => ':date, :daysdan keyin',
        'reserved' => "To'lovlarga ajratilgan",
        'payments' => "Daromadgacha to'lovlar",
        'payment_line' => ':name — :amount, :date',
        'overdue' => "muddati o'tgan",
        'no_paydays' => 'Daromad kunlari belgilanmagan, oy oxirigacha hisoblayman.',
    ],

    'month' => [
        'title' => ':month: :amount sarflandi',
        'income' => 'Daromadlar :amount',
        'one_off' => 'bir martalik xarajatlar :amount',
        'category' => 'Toifa',
        'spent' => 'Sarflandi',
        'budget' => 'Byudjet',
        'empty' => 'Bu oyda hali hech narsa yozilmagan.',
    ],

    'digest' => [
        'morning_title' => 'Xayrli tong! Bugun :amount sarflash mumkin',
        'morning_over' => "Xayrli tong! Daromadgacha bo'sh pul tugadi",
        'payments_today' => "Bugun to'lanadi",
        'weekly_title' => 'Hafta: :amount sarflandi',
        'weekly_less' => "O'tgan haftadan :amount kam.",
        'weekly_more' => "O'tgan haftadan :amount ko'p.",
        'weekly_same' => "O'tgan hafta bilan bir xil.",
        'weekly_top' => "Eng ko'p ketgan",
    ],

    'pay' => [
        'done' => "To'landi: :name, :amount",
        'need_app' => "Bu to'lovning hisobi yo'q yoki boshqa valyutada. Uni ilovada belgilang.",
        'already' => "Allaqachon to'langan deb belgilangan.",
    ],

    'commands' => [
        'today' => 'Bugun qancha sarflash mumkin',
        'month' => "Oy xarajatlari toifalar bo'yicha",
        'help' => 'Xarajatlarni qanday yozish kerak',
    ],
    'menu_button' => 'Kompas',
    'short_description' => 'Shaxsiy yordamchi: pul, odatlar, ishlar.',
    'description' => "Kompas pulni oqilona sarflashga yordam beradi: bugun qancha sarflash mumkinligini hisoblaydi va xarajatlarni bitta ibora bilan yozadi, masalan «taksi 32k».\n\nBu shaxsiy bot, faqat egasi foydalana oladi.",

    'login' => [
        'confirm' => "Kompasga kirish\nQurilma: :device\n\nAgar kirishni siz boshlamagan bo'lsangiz, «Rad etish»ni bosing.",
        'approve_button' => 'Kirish',
        'deny_button' => 'Rad etish',
        'approved' => 'Kirish tasdiqlandi. Ilovaga qaytishingiz mumkin.',
        'denied' => 'Kirish rad etildi.',
        'expired' => 'Kirish havolasi eskirgan. Ilovada «Telegram orqali kirish»ni yana bir bor bosing.',
        'expired_short' => 'Havola eskirgan',
    ],
];
