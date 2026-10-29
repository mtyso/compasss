<?php

// Push notifications on the phone (docs/PLAN.md, D36). Advice, reminders and the digests' titles reuse the
// advisor's and the bot's texts, so the phone and the chat say the same.
return [
    'morning_payments' => "Bugun to'lanadi: :names",
    'morning_tasks' => 'Bugunga :count ta ish',
    'morning_advice' => 'Maslahat: :title',
    'morning_more' => 'yana :count ta maslahat',
    'morning_free' => 'Daromadgacha bo\'sh pul: :amount',
    'test_title' => 'Bildirishnomalar ishlayapti',
    'test_body' => 'Eslatmalar, maslahatlar va hisobotlar shunday keladi.',
    'not_configured' => 'Serverda Firebase hali sozlanmagan, shuning uchun bildirishnomalar hozircha faqat Telegramga keladi.',
    'no_device' => "Bu telefon bildirishnomalarga ulanmagan. Ularni o'chirib, qayta yoqing.",
    'failed' => "Firebase bildirishnomani qabul qilmadi. Birozdan keyin urinib ko'ring.",
];
