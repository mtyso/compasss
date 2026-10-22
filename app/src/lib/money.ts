import type { Currency } from './format'

export const CURRENCIES: Currency[] = ['UZS', 'USD']
export const EXPONENT: Record<Currency, number> = { UZS: 0, USD: 2 }

const SUFFIX: Record<string, number> = { к: 1e3, k: 1e3, м: 1e6, m: 1e6, млн: 1e6, mln: 1e6 }

/**
 * Reads an amount the way people type it: «32000», «32 000», «32к», «1,5м», «12.50».
 * Returns minor units of the currency, or null when it is not a positive amount.
 */
export function parseAmount(input: string, currency: Currency): number | null {
  const match = input
    .trim()
    .toLowerCase()
    .replace(/[\s  ]/g, '')
    .match(/^(\d+(?:[.,]\d+)?)(к|k|млн|mln|м|m)?$/)
  if (!match) return null

  const value = Number(match[1].replace(',', '.')) * (match[2] ? SUFFIX[match[2]] : 1)
  const minor = Math.round(value * 10 ** EXPONENT[currency])
  return minor > 0 && Number.isSafeInteger(minor) ? minor : null
}

/** The amount as it would be typed back into the field: «32000», «12.5». */
export function amountToInput(minor: number, currency: Currency): string {
  const exponent = EXPONENT[currency]
  return exponent === 0 ? String(minor) : String(minor / 10 ** exponent)
}

/** Same rounding as the server (App\Support\Money::toUzs): half away from zero. */
export function toUzs(minor: number, currency: Currency, rate: string | number): number {
  if (currency === 'UZS') return minor
  const value = (minor / 10 ** EXPONENT[currency]) * Number(rate)
  return Math.sign(value) * Math.round(Math.abs(value))
}
