export type Currency = 'UZS' | 'USD'
export type Locale = 'ru' | 'uz'

export const TIME_ZONE = 'Asia/Tashkent'

// Amounts travel as integers in minor units (D8): UZS has no tiyin in practice, USD is stored in cents.
const EXPONENT: Record<Currency, number> = { UZS: 0, USD: 2 }

const NARROW_NBSP = ' '

function groupThousands(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, NARROW_NBSP)
}

export function formatMoney(minor: number, currency: Currency, locale: Locale = 'ru'): string {
  const exponent = EXPONENT[currency]
  const negative = minor < 0
  const abs = Math.abs(Math.trunc(minor))
  const units = Math.floor(abs / 10 ** exponent)
  const fraction = exponent > 0 ? String(abs % 10 ** exponent).padStart(exponent, '0') : ''
  const number = groupThousands(String(units)) + (fraction ? ',' + fraction : '')
  const sign = negative ? '−' : ''

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

export function formatDayHeading(value: Date, locale: Locale = 'ru'): string {
  const text = new Intl.DateTimeFormat(locale === 'uz' ? 'uz-Latn-UZ' : 'ru-RU', {
    timeZone: TIME_ZONE,
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(value)
  return text.charAt(0).toUpperCase() + text.slice(1)
}
