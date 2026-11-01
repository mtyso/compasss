/**
 * The analytics on the «Деньги» screen (docs/PLAN.md, D41), as GET /api/analytics?month= returns them, and the
 * small sums the screen draws from them. Sums in som. The week and the day leave one-offs out: they show habits.
 */
import type { Transaction } from '@/stores/money'

export interface AnalyticsDay {
  date: string
  expense_uzs: number
  one_off_uzs: number
  count: number
}

export interface AnalyticsMonth {
  month: string
  expense_uzs: number
  one_off_uzs: number
  income_uzs: number
}

/** ISO weekday: 1 is Monday, 7 Sunday. `days` is how many of them the month has had so far. */
export interface AnalyticsWeekday {
  weekday: number
  total_uzs: number
  count: number
  days: number
}

export interface AnalyticsHour {
  hour: number
  total_uzs: number
  count: number
}

export interface MonthAnalytics {
  month: string
  first_month: string | null
  /** The app saw the month from its start. */
  tracked: boolean
  over: boolean
  /** Today, for a month still going. */
  today: string | null
  /** Days that count for an average: from the first the app saw up to today. */
  counted_days: number
  days: AnalyticsDay[]
  /** The month before, day by day; null before the app's first month. */
  prev: { month: string; tracked: boolean; days: { date: string; expense_uzs: number }[] } | null
  /** This month and up to five before it that the app saw, oldest first. */
  months: AnalyticsMonth[]
  weekdays: AnalyticsWeekday[]
  hours: AnalyticsHour[]
  /** By the category written down, with its main category; biggest first. */
  subcategories: { category_id: number; root_id: number; total_uzs: number; count: number }[]
  /** The biggest expenses of the month. */
  top: Transaction[]
}

/** The running total: what was spent up to and including each day. */
export function runningTotals(values: number[]): number[] {
  let sum = 0
  return values.map((value) => (sum += value))
}

/** The day of the month (1-based) the comparison runs to: today, or the last day of a month that is over. */
export function lastDay(data: Pick<MonthAnalytics, 'today' | 'days'>): number {
  return data.today ? Number(data.today.slice(8, 10)) : data.days.length
}

export interface PacePoint {
  /** Day of the month, from 1. */
  day: number
  now: number
  /** The month before by the same day (its last day when it was shorter); null without one. */
  then: number | null
  /** Now against then, in whole percent: −12 is 12% less. Null when there is nothing to compare with. */
  diff: number | null
}

/** This month's running total by a day against the month before by the same day of the month. */
export function paceAt(data: Pick<MonthAnalytics, 'days' | 'prev'>, day: number): PacePoint {
  const now = runningTotals(data.days.map((d) => d.expense_uzs))[day - 1] ?? 0
  const before = data.prev ? runningTotals(data.prev.days.map((d) => d.expense_uzs)) : null
  const then = before ? (before[Math.min(day, before.length) - 1] ?? 0) : null
  return { day, now, then, diff: then ? Math.round(((now - then) * 100) / then) : null }
}

export interface MonthStats {
  /** Expenses written down. */
  count: number
  /** The average expense. */
  average: number
  /** Regular spending (one-offs left out) per day the app saw. */
  perDay: number
  /** Days the app saw without a single expense; today does not count until it is over. */
  noSpend: number
  /** The day with the most spending, null without any. */
  biggest: AnalyticsDay | null
}

export function monthStats(data: Pick<MonthAnalytics, 'days' | 'counted_days' | 'today'>): MonthStats {
  const count = data.days.reduce((sum, d) => sum + d.count, 0)
  const total = data.days.reduce((sum, d) => sum + d.expense_uzs, 0)
  const regular = total - data.days.reduce((sum, d) => sum + d.one_off_uzs, 0)
  const biggest = data.days.reduce<AnalyticsDay | null>((best, d) => (d.expense_uzs > (best?.expense_uzs ?? 0) ? d : best), null)
  // The counted days are the last ones up to today; the app may have started in the middle of the month.
  const gone = data.today ? data.days.filter((d) => d.date <= data.today!) : data.days
  const counted = data.counted_days ? gone.slice(-data.counted_days) : []
  return {
    count,
    average: count ? Math.round(total / count) : 0,
    perDay: data.counted_days ? Math.round(regular / data.counted_days) : 0,
    noSpend: counted.filter((d) => !d.expense_uzs && d.date !== data.today).length,
    biggest,
  }
}

/** Monday first, the way the week is read here; the average is per such day so far. */
export function weekdayAverages(weekdays: AnalyticsWeekday[]): (AnalyticsWeekday & { average: number })[] {
  return [...weekdays]
    .sort((a, b) => a.weekday - b.weekday)
    .map((w) => ({ ...w, average: w.days ? Math.round(w.total_uzs / w.days) : 0 }))
}

/** The weekday with the highest average, when one stands out from the rest by at least a fifth. */
export function peakWeekday(weekdays: AnalyticsWeekday[]): (AnalyticsWeekday & { average: number }) | null {
  const list = weekdayAverages(weekdays).filter((w) => w.days > 0)
  const sorted = [...list].sort((a, b) => b.average - a.average)
  const [top, next] = sorted
  if (!top || !top.average) return null
  return !next || top.average >= next.average * 1.2 ? top : null
}

/**
 * The three hours in a row (across midnight too) when most of the money goes, and their share of it.
 * Null without spending, or when the money spreads so evenly that no window holds even a third of it.
 */
export function peakHours(hours: AnalyticsHour[], width = 3): { from: number; to: number; share: number } | null {
  const byHour = Array.from({ length: 24 }, (_, hour) => hours.find((h) => h.hour === hour)?.total_uzs ?? 0)
  const total = byHour.reduce((sum, value) => sum + value, 0)
  if (!total) return null
  let best = { from: 0, sum: -1 }
  for (let from = 0; from < 24; from++) {
    let sum = 0
    for (let i = 0; i < width; i++) sum += byHour[(from + i) % 24]!
    // On a tie, a window that starts with spending reads better: «с 23 до 2», not «с 22 до 1».
    if (sum > best.sum || (sum === best.sum && !byHour[best.from] && byHour[from]! > 0)) best = { from, sum }
  }
  const share = best.sum / total
  return share >= 1 / 3 ? { from: best.from, to: (best.from + width) % 24, share: Math.round(share * 100) } : null
}

/** A main category's spending split by the subcategories it was written under, biggest first. */
export function subcategoriesOf(data: Pick<MonthAnalytics, 'subcategories'>, rootId: number): MonthAnalytics['subcategories'] {
  return data.subcategories.filter((row) => row.root_id === rootId)
}
