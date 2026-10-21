import { createI18n } from 'vue-i18n'
import ru from './ru'
import uz from './uz'

export const i18n = createI18n({
  legacy: false,
  locale: 'ru',
  fallbackLocale: 'ru',
  messages: { ru, uz },
})
