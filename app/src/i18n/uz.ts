import type ru from './ru'

// Uzbek (Latin). Draft translations, to be reviewed by the owner.
const uz: typeof ru = {
  appName: 'Kompas',
  nav: {
    today: 'Bugun',
    money: 'Pul',
    add: 'Yozish',
    tasks: 'Ishlar',
    more: 'Yana',
  },
  today: {
    title: 'Bugun',
    limit: 'Bugun sarflash mumkin',
    limitEmpty: "Hisoblar, daromad kunlari va doimiy to'lovlarni qo'shing, shunda bu yerda kunlik limit paydo bo'ladi.",
    advice: 'Maslahatlar',
    adviceEmpty: "Birinchi operatsiyalar paydo bo'lgach, maslahatchi yordam bera boshlaydi.",
    habits: 'Odatlar',
    habitsEmpty: "Hozircha odatlar yo'q.",
    tasks: 'Vazifalar',
    tasksEmpty: "Bugun uchun vazifalar yo'q.",
  },
  money: {
    title: 'Pul',
    empty: "Bu yerda hisoblar, operatsiyalar va oylik byudjet bo'ladi.",
  },
  tasks: {
    title: 'Ishlar',
    empty: "Bu yerda vazifalar va odatlar bo'ladi.",
  },
  more: {
    title: 'Yana',
    language: 'Til',
    theme: 'Mavzu',
    themes: { system: 'Tizimdagidek', light: "Yorug'", dark: "Qorong'i" },
    server: 'Server',
    serverOk: 'aloqada',
    serverDown: 'mavjud emas',
    account: 'Akkaunt',
    changePin: "PIN-kodni o'zgartirish",
    logout: 'Chiqish',
    logoutConfirm: 'Bu qurilmadan chiqasizmi?',
    logoutYes: 'Chiqish',
    cancel: 'Bekor qilish',
  },
  quickAdd: {
    title: 'Tez yozish',
    soon: "Xarajat va daromadlarni yozish keyingi bosqichda paydo bo'ladi.",
    close: 'Yopish',
  },
  login: {
    title: 'Kirish',
    subtitle: 'Pul, odatlar va ishlar bir joyda.',
    telegram: 'Telegram orqali kirish',
    hint: 'Telegramda Kompas boti ochiladi. Parol kerak emas.',
    waiting: 'Telegramda kirishni tasdiqlang',
    step1: "Agar bot o'zi ochilmagan bo'lsa, uni oching.",
    step2: '«Start»ni bosing.',
    step3: 'Bot xabari ostidagi «Kirish»ni bosing.',
    openTelegram: 'Telegramni ochish',
    cancel: 'Bekor qilish',
    denied: 'Kirish Telegramda rad etildi.',
    expired: "Havola eskirgan, yana urinib ko'ring.",
    error: "Server javob bermadi. Internetni tekshirib, yana urinib ko'ring.",
    signedOut: {
      pin: "PIN-kod juda ko'p marta noto'g'ri kiritildi. Qaytadan kiring.",
      revoked: 'Seans tugadi. Qaytadan kiring.',
    },
  },
  pin: {
    create: "PIN-kod o'ylab toping",
    createHint: "To'rt raqam. Kompas har safar ochilganda ularni so'raydi.",
    repeat: 'PIN-kodni takrorlang',
    mismatch: "PIN-kodlar mos kelmadi. Yana urinib ko'ring.",
    enter: 'PIN-kodni kiriting',
    hello: 'Salom, {name}',
    wrong: "Noto'g'ri PIN. Qolgan urinishlar: {n}",
    forgot: 'PIN-kodni unutdingizmi? Qaytadan kirish',
    erase: "O'chirish",
  },
}

export default uz
