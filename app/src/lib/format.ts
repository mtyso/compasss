export type Currency = 'UZS' | 'USD'
export type Locale = 'ru' | 'uz'

export const TIME_ZONE = 'Asia/Tashkent'

// Amounts travel as integers in minor units (D8): UZS has no tiyin in practice, USD is stored in cents.
const EXPONENT: Record<Currency, number> = { UZS: 0, USD: 2 }

const NARROW_NBSP = ' '

function groupThousands(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, NARROW_NBSP)
}

/** The amount alone, «1 250 000» or «20,50», for places where the currency is already clear. */
export function formatNumber(minor: number, currency: Currency): string {
  const exponent = EXPONENT[currency]
  const abs = Math.abs(Math.trunc(minor))
  const units = Math.floor(abs / 10 ** exponent)
  const fraction = exponent > 0 ? String(abs % 10 ** exponent).padStart(exponent, '0') : ''
  return (minor < 0 ? '−' : '') + groupThousands(String(units)) + (fraction ? ',' + fraction : '')
}

export function formatMoney(minor: number, currency: Currency, locale: Locale = 'ru'): string {
  const number = formatNumber(Math.abs(minor), currency)
  const sign = minor < 0 ? '−' : ''

  if (currency === 'USD') {
    return `${sign}$${number}`
  }
  return `${sign}${number} ${locale === 'uz' ? "so'm" : 'сум'}`
}

export function formatDate(value: Date | string): string {
  const date = typeof value === 'string' ? new Date(value) : value
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: TIME_ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}

// Browsers ship Uzbek month names unevenly (Chromium prints «M10 21, Wed»), so Uzbek dates are spelled out here.
const UZ_MONTHS = ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avgust', 'sentabr', 'oktabr', 'noyabr', 'dekabr']
const UZ_WEEKDAYS = ['yakshanba', 'dushanba', 'seshanba', 'chorshanba', 'payshanba', 'juma', 'shanba']
const EN_WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

const capitalize = (text: string) => text.charAt(0).toUpperCase() + text.slice(1)

/** Month, day and weekday (0 = Sunday) of a moment, as the calendar in Tashkent shows it. */
function tashkentDay(value: Date): { month: number; day: number; weekday: number } {
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: TIME_ZONE, month: 'numeric', day: 'numeric', weekday: 'short' }).formatToParts(value)
  const part = (type: string) => parts.find((p) => p.type === type)?.value ?? ''
  return { month: Number(part('month')), day: Number(part('day')), weekday: EN_WEEKDAYS.indexOf(part('weekday')) }
}

/** «Среда, 21 октября», «Chorshanba, 21-oktabr». */
export function formatDayHeading(value: Date, locale: Locale = 'ru'): string {
  if (locale === 'uz') {
    const { month, day, weekday } = tashkentDay(value)
    return `${capitalize(UZ_WEEKDAYS[weekday])}, ${day}-${UZ_MONTHS[month - 1]}`
  }
  return capitalize(
    new Intl.DateTimeFormat('ru-RU', { timeZone: TIME_ZONE, weekday: 'long', day: 'numeric', month: 'long' }).format(value),
  )
}

/** «10 ноября», «10-noyabr»: a calendar date (YYYY-MM-DD) in words, without the year. */
export function formatDayMonth(day: string, locale: Locale = 'ru'): string {
  if (locale === 'uz') {
    return `${Number(day.slice(8, 10))}-${UZ_MONTHS[Number(day.slice(5, 7)) - 1]}`
  }
  return new Intl.DateTimeFormat('ru-RU', { timeZone: TIME_ZONE, day: 'numeric', month: 'long' }).format(new Date(`${day}T12:00:00+05:00`))
}

/** «Октябрь 2026», «Oktabr 2026» for a month given as YYYY-MM. */
export function formatMonthTitle(month: string, locale: Locale = 'ru'): string {
  if (locale === 'uz') {
    return `${capitalize(UZ_MONTHS[Number(month.slice(5, 7)) - 1])} ${month.slice(0, 4)}`
  }
  return capitalize(
    new Intl.DateTimeFormat('ru-RU', { timeZone: TIME_ZONE, month: 'long', year: 'numeric' })
      .format(new Date(`${month}-15T12:00:00+05:00`))
      .replace(/\s*г\.$/, ''),
  )
}
