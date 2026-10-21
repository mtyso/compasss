// What the quick-add sheet shows under the amount while you type (docs/PLAN.md, 2.4): how today's
// limit and the category budget change. Mirrors the server's App\Advisor\DailyLimit rules: everyday
// spending comes out of today's allowance, a one-off comes out of the free money and lowers the
// limit for all the days left.

export interface LimitNow {
  limit_uzs: number
  spent_today_uzs: number
  left_today_uzs: number
  free_uzs: number
  days_left: number
}

export type LimitHint =
  | { kind: 'left'; amount: number }
  | { kind: 'overToday'; amount: number }
  | { kind: 'limitDrops'; amount: number }
  | { kind: 'short'; amount: number }

export type BudgetHint = { kind: 'budget'; percent: number } | { kind: 'budgetOver'; amount: number }

export function limitHint(amountUzs: number, limit: LimitNow, oneOff: boolean): LimitHint {
  const free = Math.max(0, limit.free_uzs)
  if (amountUzs > free) return { kind: 'short', amount: amountUzs - free }
  if (oneOff) {
    return { kind: 'limitDrops', amount: Math.floor((free - amountUzs + limit.spent_today_uzs) / Math.max(1, limit.days_left)) }
  }
  if (amountUzs <= limit.left_today_uzs) return { kind: 'left', amount: limit.left_today_uzs - amountUzs }
  return { kind: 'overToday', amount: amountUzs - Math.max(0, limit.left_today_uzs) }
}

export function budgetHint(amountUzs: number, budget: { limit_uzs: number; spent_uzs: number }): BudgetHint {
  const after = budget.spent_uzs + amountUzs
  if (after > budget.limit_uzs) return { kind: 'budgetOver', amount: after - budget.limit_uzs }
  return { kind: 'budget', percent: Math.round((after / budget.limit_uzs) * 100) }
}
