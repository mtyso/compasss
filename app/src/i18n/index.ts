import { createI18n } from 'vue-i18n'
import ru from './ru'
import uz from './uz'

/** Russian has three plural forms: 1 день, 2 дня, 5 дней. Uzbek nouns do not change with numbers. */
function russianPlural(choice: number, choicesLength: number): number {
  if (choicesLength < 3) return choice === 1 ? 0 : 1
  const n = Math.abs(choice) % 100
  const last = n % 10
  if (n > 10 && n < 20) return 2
  if (last === 1) return 0
  if (last >= 2 && last <= 4) return 1
  return 2
}

export const i18n = createI18n({
  legacy: false,
  locale: 'ru',
  fallbackLocale: 'ru',
  messages: { ru, uz },
  pluralRules: { ru: russianPlural },
})
