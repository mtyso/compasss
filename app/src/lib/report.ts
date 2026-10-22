/**
 * «Итоги месяца» (docs/PLAN.md, D30), as GET /api/reports/month returns them: sums in som, a goal's own
 * sums in its currency. «Обычно» is the median of up to three earlier months, one-offs left out.
 */
import type { Currency } from './format'
import type { GoalType } from '@/stores/goals'

export interface ReportCategory {
  category_id: number
  total_uzs: number
  one_off_uzs: number
  /** Null until the app has a whole month before this one. */
  usual_uzs: number | null
  limit_uzs: number | null
}

export interface ReportGoal {
  id: number
  title: string
  type: GoalType
  icon: string | null
  currency: Currency
  target: number
  /** Put in during the month; for a debt, what was paid. */
  added: number
  added_uzs: number
  saved: number
  progress_from: number
  progress_to: number
  done: boolean
}

export interface MonthReport {
  month: string
  first_month: string | null
  /** The app saw the month from its start. */
  tracked: boolean
  /** The month is over; otherwise the numbers are so far. */
  over: boolean
  /** Share of the month gone by, 1 when it is over. */
  pace: number
  income_uzs: number
  expense_uzs: number
  one_off_uzs: number
  saved_uzs: number
  rate: number | null
  usual_uzs: number | null
  history: string[]
  prev: { month: string; income_uzs: number; expense_uzs: number; rate: number | null } | null
  categories: ReportCategory[]
  budget: { limit_uzs: number; spent_uzs: number; kept: number; count: number } | null
  goals: ReportGoal[]
  goals_added_uzs: number
  debts_paid_uzs: number
}

export interface Mover {
  category_id: number
  /** Regular spending this month, one-offs left out. */
  regular: number
  usual: number
  diff: number
}

// A change smaller than this is noise, not news.
const MIN_DIFF = 50_000
const MIN_SHARE = 0.15

/** The share of a usual sum that falls on the days gone by, to a thousand: a guess should not look exact. */
const share = (usual: number, pace: number) => (pace >= 1 ? usual : Math.round((usual * pace) / 1000) * 1000)

/**
 * Categories that went clearly above or below their usual level, the biggest change first. `pace` is the
 * share of the month gone by: a month still going is compared with the usual level up to today. The bot's
 * monthly report picks them the same way (App\Money\MonthReport::movers).
 */
export function movers(categories: ReportCategory[], pace = 1, limit = 3): { up: Mover[]; down: Mover[] } {
  const all = categories
    .filter((c): c is ReportCategory & { usual_uzs: number } => c.usual_uzs !== null)
    .map((c) => {
      const regular = c.total_uzs - c.one_off_uzs
      const usual = share(c.usual_uzs, pace)
      return { category_id: c.category_id, regular, usual, diff: regular - usual }
    })
    .filter((m) => Math.abs(m.diff) >= Math.max(MIN_DIFF, m.usual * MIN_SHARE))
  return {
    up: all.filter((m) => m.diff > 0).sort((a, b) => b.diff - a.diff).slice(0, limit),
    down: all.filter((m) => m.diff < 0).sort((a, b) => a.diff - b.diff).slice(0, limit),
  }
}

/** The usual level of spending by now: all of it for a month that is over, its share for one still going. */
export function usualNow(report: Pick<MonthReport, 'usual_uzs' | 'pace'>): number | null {
  return report.usual_uzs === null ? null : share(report.usual_uzs, report.pace)
}

/** Regular spending against the usual level by now, in whole percent: −12 is 12% less. Null without one. */
export function againstUsual(report: Pick<MonthReport, 'expense_uzs' | 'one_off_uzs' | 'usual_uzs' | 'pace'>): number | null {
  const usual = usualNow(report)
  if (!usual) return null
  return Math.round(((report.expense_uzs - report.one_off_uzs - usual) * 100) / usual)
}
