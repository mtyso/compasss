import type { Currency } from '@/lib/format'
import { MONTH_DAYS, dateAfter, daysBetween, monthlyFor, roundUp } from '@/lib/goals'

/**
 * The arithmetic of the calculators (docs/PLAN.md, stage 2): a loan paid month by month, a deposit that
 * grows with its interest, and a purchase compared across ways to pay for it. Money is in minor units of
 * one currency; rates are yearly percents, counted a twelfth a month as banks quote them.
 */

export type PaymentKind = 'annuity' | 'differentiated'

export interface LoanInput {
  principal: number
  months: number
  ratePercent: number
  kind: PaymentKind
  /** Paid on top every month: the term gets shorter, the payment stays. */
  extraMonthly?: number
}

export interface LoanRow {
  month: number
  payment: number
  interest: number
  principal: number
  balance: number
}

export interface LoanResult {
  rows: LoanRow[]
  firstPayment: number
  lastPayment: number
  totalPaid: number
  interest: number
  months: number
}

const MAX_MONTHS = 600

/**
 * A loan month by month. An annuity pays the same each month (the last one evens out the rounding); a
 * differentiated loan repays an equal share of the debt plus the interest on what is left, so it starts
 * higher and goes down. Interest is counted on the debt at the start of each month.
 */
export function loanSchedule({ principal, months, ratePercent, kind, extraMonthly = 0 }: LoanInput): LoanResult {
  const n = Math.max(1, Math.min(MAX_MONTHS, Math.round(months)))
  const r = Math.max(0, ratePercent) / 1200
  const extra = Math.max(0, extraMonthly)
  const annuity = monthlyFor(principal, n, ratePercent)
  const share = Math.ceil(principal / n)

  const rows: LoanRow[] = []
  let balance = Math.max(0, principal)
  for (let month = 1; balance > 0 && month <= MAX_MONTHS; month++) {
    const interest = Math.round(balance * r)
    const scheduled = kind === 'annuity' ? annuity : share + interest
    const payment = Math.min(balance + interest, scheduled + extra)
    balance = balance + interest - payment
    rows.push({ month, payment, interest, principal: payment - interest, balance })
  }

  const totalPaid = rows.reduce((sum, row) => sum + row.payment, 0)
  return {
    rows,
    firstPayment: rows[0]?.payment ?? 0,
    lastPayment: rows.at(-1)?.payment ?? 0,
    totalPaid,
    interest: totalPaid - Math.max(0, principal),
    months: rows.length,
  }
}

export interface DepositInput {
  initial: number
  /** Put in at the end of each month. */
  monthly: number
  ratePercent: number
  months: number
  /** Interest added to the deposit each month and earning interest itself; otherwise paid out separately. */
  capitalize: boolean
}

export interface DepositResult {
  total: number
  interest: number
  contributed: number
  rows: { month: number; balance: number; interest: number }[]
}

/** What a deposit comes to after `months`, with monthly top-ups and interest counted monthly. */
export function depositGrowth({ initial, monthly, ratePercent, months, capitalize }: DepositInput): DepositResult {
  const n = Math.max(0, Math.min(MAX_MONTHS, Math.round(months)))
  const r = Math.max(0, ratePercent) / 1200
  let balance = Math.max(0, initial)
  let paidOut = 0
  const rows: DepositResult['rows'] = []
  for (let month = 1; month <= n; month++) {
    const interest = Math.round(balance * r)
    if (capitalize) balance += interest
    else paidOut += interest
    balance += Math.max(0, monthly)
    rows.push({ month, balance: balance + paidOut, interest })
  }
  const contributed = Math.max(0, initial) + Math.max(0, monthly) * n
  const total = balance + paidOut
  return { total, interest: total - contributed, contributed, rows }
}

/**
 * Months until `saved` grows to `target` at `monthly` a month, on a deposit with monthly compounding
 * (top-ups at the end of each month, as in depositGrowth). Null when nothing is put in and nothing grows.
 */
export function monthsToSave(target: number, saved: number, monthly: number, ratePercent = 0): number | null {
  const left = target - saved
  if (left <= 0) return 0
  const r = Math.max(0, ratePercent) / 1200
  if (r <= 0) return monthly > 0 ? left / monthly : null
  if (monthly <= 0 && saved <= 0) return null
  return Math.log((target * r + monthly) / (Math.max(0, saved) * r + monthly)) / Math.log(1 + r)
}

/** What a sum a month does to the daily limit, in the same units a day (average month). */
export function perDay(monthly: number): number {
  return Math.round(monthly / MONTH_DAYS)
}

export type BuyKind = 'cash' | 'installment' | 'credit'

export interface BuyOption {
  kind: BuyKind
  /** The price for this way of paying: a dealer's installment price can be higher than for cash. */
  price: number
  downPercent: number
  months: number
  ratePercent: number
}

export interface BuyPlan {
  option: BuyOption
  /** Collected before buying: the whole price for cash, the down payment otherwise. */
  down: number
  loan: number
  payment: number
  /** Down payment plus every payment after buying. */
  totalPaid: number
  /** Paid above the base price. */
  overpay: number
  /** Deposit interest earned while collecting the down payment. */
  earned: number
  saveMonths: number | null
  buyOn: string | null
  /** The day of the last payment; the purchase day for cash. */
  freeOn: string | null
}

export interface PurchaseInput {
  basePrice: number
  saved: number
  monthly: number
  /** Yearly rate of the deposit the money waits on; 0 when it simply lies there. */
  depositRate: number
  currency: Currency
  today: string
}

/** The down payment of a price: a share of it rounded up to a step that reads well, as the goals count it (D26). */
export function downPayment(price: number, percent: number, currency: Currency): number {
  return Math.min(price, roundUp(Math.ceil((price * percent) / 100), currency))
}

/**
 * One way of paying for a purchase: when the money for it is collected at `monthly` a month (on a deposit,
 * if there is a rate), what is paid a month afterwards, and what it costs in all.
 */
export function planPurchase(option: BuyOption, input: PurchaseInput): BuyPlan {
  const cash = option.kind === 'cash'
  const down = cash ? option.price : downPayment(option.price, option.downPercent, input.currency)
  const loan = option.price - down
  const schedule = loan > 0 ? loanSchedule({ principal: loan, months: option.months, ratePercent: option.ratePercent, kind: 'annuity' }) : null
  const totalPaid = down + (schedule?.totalPaid ?? 0)

  const saveMonths = monthsToSave(down, input.saved, input.monthly, input.depositRate)
  const own = saveMonths === null ? down : Math.min(down, Math.max(0, input.saved) + Math.max(0, input.monthly) * saveMonths)
  const buyOn = saveMonths === null ? null : dateAfter(input.today, saveMonths)

  return {
    option,
    down,
    loan,
    payment: schedule?.firstPayment ?? 0,
    totalPaid,
    overpay: totalPaid - input.basePrice,
    earned: Math.max(0, Math.round(down - own)),
    saveMonths,
    buyOn,
    freeOn: buyOn === null ? null : schedule ? dateAfter(buyOn, schedule.months) : buyOn,
  }
}

/** What a plan costs in the end: everything paid, less what the deposit earned meanwhile. */
export function netCost(plan: BuyPlan): number {
  return plan.totalPaid - plan.earned
}

export interface PurchaseComparison {
  plans: BuyPlan[]
  /** The plans bought soonest, and those that cost least: several when they tie, none when all of them do. */
  soonest: number[]
  cheapest: number[]
}

/** The plans side by side, with the ones bought soonest and the ones that cost least marked. */
export function comparePurchase(options: BuyOption[], input: PurchaseInput): PurchaseComparison {
  const plans = options.map((option) => planPurchase(option, input))
  const reachable = plans.map((plan, i) => ({ plan, i })).filter(({ plan }) => plan.buyOn !== null)
  const best = <T>(value: (plan: BuyPlan) => T): number[] => {
    if (reachable.length < 2) return []
    const values = reachable.map(({ plan }) => value(plan))
    const top = values.reduce((a, b) => (b < a ? b : a))
    const winners = reachable.filter((_, k) => values[k] === top).map(({ i }) => i)
    return winners.length === reachable.length ? [] : winners
  }
  return { plans, soonest: best((plan) => plan.buyOn!), cheapest: best(netCost) }
}

export type PurchaseSummary =
  | { kind: 'both'; index: number }
  | { kind: 'tradeoff'; fast: number; cheap: number; daysSooner: number; costsMore: number }

/**
 * The comparison in one sentence: one way is both the soonest and the cheapest, or the soonest one costs
 * this much more than the cheapest one and buys that many days sooner.
 */
export function summarizePurchase({ plans, soonest, cheapest }: PurchaseComparison): PurchaseSummary | null {
  const both = soonest.find((i) => cheapest.includes(i))
  if (both !== undefined) return { kind: 'both', index: both }
  if (!soonest.length || !cheapest.length) return null
  const fast = soonest[0]!
  const cheap = cheapest[0]!
  return {
    kind: 'tradeoff',
    fast,
    cheap,
    daysSooner: daysBetween(plans[fast]!.buyOn!, plans[cheap]!.buyOn!),
    costsMore: netCost(plans[fast]!) - netCost(plans[cheap]!),
  }
}
