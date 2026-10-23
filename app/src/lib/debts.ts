/**
 * Paying several debts off (docs/PLAN.md, D29), month by month in som. Every debt gets its own payment;
 * the money beyond them, the extra and the payments of debts already paid off, goes to the first debt
 * still open in the chosen order. The avalanche puts the dearest first and pays the least interest; the
 * snowball puts the smallest first, so a debt is gone soonest. The server orders them the same way
 * (App\Money\Debts::order).
 */
export type DebtMethod = 'avalanche' | 'snowball'

export interface DebtInput {
  id: number
  /** What is owed, in som. */
  amount: number
  /** Yearly interest, percent; null when there is none or nobody said. */
  rate: number | null
  /** The payment a month, in som; 0 for a debt with no plan. */
  payment: number
  /** What is still to pay this month, when it differs: paid already, or borrowed this month and due from the next. */
  first?: number
}

export interface Payoff {
  /** Months until the last debt is gone, or null when the payments never get there. */
  months: number | null
  /** Interest paid on the way, in som. */
  interest: number
  /** The debts in the order they are paid, each with the month it goes in (1 is this month). */
  order: { id: number; month: number | null }[]
}

// Fifty years: a plan that takes longer does not pay the debts off.
const MAX_MONTHS = 600

/** Avalanche: the highest rate first, the smaller of equal ones. Snowball: the smallest first. */
export function orderDebts<T extends { amount: number; rate: number | null }>(debts: T[], method: DebtMethod): T[] {
  const byRate = (a: T, b: T) => (b.rate ?? 0) - (a.rate ?? 0)
  const bySize = (a: T, b: T) => a.amount - b.amount
  return [...debts].sort((a, b) => (method === 'snowball' ? bySize(a, b) || byRate(a, b) : byRate(a, b) || bySize(a, b)))
}

/**
 * How the debts go at `extra` a month beyond the payments, in the `method`'s order. With `method` null they
 * go as they do now: each on its own payment, nothing moved from one to another, no extra.
 */
export function payoff(debts: DebtInput[], extra: number, method: DebtMethod | null): Payoff {
  const ordered = method ? orderDebts(debts, method) : debts
  const left = new Map(ordered.map((d) => [d.id, d.amount]))
  const doneIn = new Map<number, number>()
  const paymentIn = (debt: DebtInput, month: number) => (month === 1 ? (debt.first ?? debt.payment) : debt.payment)
  let interest = 0

  for (let month = 1; month <= MAX_MONTHS && doneIn.size < ordered.length; month++) {
    let spare = method ? ordered.reduce((sum, d) => sum + paymentIn(d, month), 0) + Math.max(0, extra) : 0
    for (const debt of ordered) {
      let owed = left.get(debt.id)!
      if (owed <= 0) continue
      const added = Math.round((owed * (debt.rate ?? 0)) / 1200)
      interest += added
      owed += added
      const pay = Math.min(paymentIn(debt, month), owed)
      left.set(debt.id, owed - pay)
      spare -= pay
    }
    // What the payments did not use goes down the list.
    for (const debt of method ? ordered : []) {
      if (spare <= 0) break
      const owed = left.get(debt.id)!
      if (owed <= 0) continue
      const pay = Math.min(spare, owed)
      left.set(debt.id, owed - pay)
      spare -= pay
    }
    for (const debt of ordered) if (left.get(debt.id)! <= 0 && !doneIn.has(debt.id)) doneIn.set(debt.id, month)
  }

  return {
    months: doneIn.size === ordered.length ? Math.max(0, ...doneIn.values()) : null,
    interest,
    order: ordered.map((d) => ({ id: d.id, month: doneIn.get(d.id) ?? null })),
  }
}
