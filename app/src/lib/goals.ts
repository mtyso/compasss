import type { Currency } from '@/lib/format'
import { isoDate, shiftDate } from '@/lib/dates'

/**
 * The arithmetic of goals, the same as the server's App\Goals\GoalMath: how long a sum takes at a
 * monthly contribution, what a deadline asks for a month, a debt with interest paid month by month.
 * Months are average ones (30.4375 days), so dates come out the same here and on the server.
 */
export const MONTH_DAYS = 30.4375

/**
 * Months until `remaining` is collected at `monthly` a month. With a yearly rate the remaining is a debt
 * that keeps growing: null when the payment does not cover the interest, or when nothing is put in.
 */
export function monthsToReach(remaining: number, monthly: number, ratePercent: number | null = null): number | null {
  if (remaining <= 0) return 0
  if (monthly <= 0) return null
  const r = (ratePercent ?? 0) / 1200
  if (r <= 0) return remaining / monthly
  if (r * remaining >= monthly) return null
  return -Math.log(1 - (r * remaining) / monthly) / Math.log(1 + r)
}

/** What has to go in a month to collect `remaining` in `months` (an annuity payment for a debt). */
export function monthlyFor(remaining: number, months: number, ratePercent: number | null = null): number {
  if (remaining <= 0) return 0
  const n = Math.max(1, months)
  const r = (ratePercent ?? 0) / 1200
  if (r <= 0) return Math.ceil(remaining / n)
  return Math.ceil((r * remaining) / (1 - (1 + r) ** -n))
}

/** Rounds a contribution up to a step that reads well: 1 000 som, or a whole dollar. */
export function roundUp(amount: number, currency: Currency): number {
  const step = currency === 'UZS' ? 1000 : 100
  return Math.ceil(amount / step) * step
}

/** The day `months` from `from` (YYYY-MM-DD), counted in average months. */
export function dateAfter(from: string, months: number): string {
  return shiftDate(from, Math.ceil(months * MONTH_DAYS))
}

/** Whole days from one day to another (YYYY-MM-DD), negative when `to` is earlier. */
export function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / 86_400_000)
}

/** When a goal is reached at `monthly` a month from today, or null when it never is. */
export function forecastAt(remaining: number, monthly: number, ratePercent: number | null, today: string = isoDate()): string | null {
  const months = monthsToReach(remaining, monthly, ratePercent)
  return months === null ? null : dateAfter(today, months)
}

/** How many days sooner a goal is reached with `extra` more a month: what a lever is worth. */
export function daysSooner(remaining: number, monthly: number, extra: number, ratePercent: number | null = null): number | null {
  const before = monthsToReach(remaining, monthly, ratePercent)
  const after = monthsToReach(remaining, monthly + extra, ratePercent)
  if (after === null) return null
  if (before === null) return Infinity
  return Math.max(0, Math.round((before - after) * MONTH_DAYS))
}

/**
 * What a different monthly contribution does to the daily limit, in som a day: putting more into a
 * goal leaves less to spend each day, and less leaves more. `fxRate` is som per unit of the goal's currency.
 */
export function limitChangePerDay(monthlyNow: number, monthlyNew: number, currency: Currency, fxRate: string | null): number {
  const rate = currency === 'UZS' ? 1 : Number(fxRate ?? 0)
  const unit = currency === 'UZS' ? 1 : 100
  return -Math.round((((monthlyNew - monthlyNow) / unit) * rate) / MONTH_DAYS)
}

/**
 * The range the «Как быстрее» slider offers: from a little, since a goal may save less than its deadline needs
 * (a debt starts at what its deadline needs), to well past both the contribution and what the deadline needs.
 */
export function sliderRange(monthlyRequired: number, monthly: number, remaining: number, currency: Currency, debt = false): { min: number; max: number; step: number } {
  const step = currency === 'UZS' ? (Math.max(monthly, monthlyRequired) >= 5_000_000 ? 100_000 : 10_000) : 1000
  const min = debt && monthlyRequired > 0 ? monthlyRequired : step
  const max = Math.max(min + step, Math.min(remaining, Math.max(monthly * 3, monthlyRequired * 1.5, min)))
  return { min, max: Math.ceil(max / step) * step, step }
}

export type GoalTone = 'money' | 'habit' | 'danger' | 'goal' | 'neutral'

/** Colour of a goal's status: green on track or ahead, amber behind, red late. */
export function statusTone(status: string): GoalTone {
  switch (status) {
    case 'ahead':
    case 'on_track':
    case 'reached':
      return 'money'
    case 'behind':
      return 'habit'
    case 'late':
      return 'danger'
    case 'done':
      return 'neutral'
    default:
      return 'goal'
  }
}
