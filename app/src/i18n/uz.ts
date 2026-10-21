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
  },
}

export default uz
