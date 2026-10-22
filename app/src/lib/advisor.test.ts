import { describe, expect, it } from 'vitest'
import { budgetHint, limitHint } from './advisor'

// 200 000 a day, 50 000 already spent, 3 950 000 free for 20 days
const limit = { limit_uzs: 200_000, spent_today_uzs: 50_000, left_today_uzs: 150_000, free_uzs: 3_950_000, days_left: 20 }

describe('limitHint', () => {
  it('shows what is left of today', () => {
    expect(limitHint(30_000, limit, false)).toEqual({ kind: 'left', amount: 120_000 })
  })

  it('shows the overspend of the day for everyday spending', () => {
    expect(limitHint(180_000, limit, false)).toEqual({ kind: 'overToday', amount: 30_000 })
  })

  it('spreads a one-off over the days left, like the server', () => {
    // (3 950 000 − 1 000 000 + 50 000) / 20
    expect(limitHint(1_000_000, limit, true)).toEqual({ kind: 'limitDrops', amount: 150_000 })
  })

  it('says how much is missing beyond the free money', () => {
    expect(limitHint(4_000_000, limit, false)).toEqual({ kind: 'short', amount: 50_000 })
    expect(limitHint(10_000, { ...limit, free_uzs: -5_000, left_today_uzs: -5_000 }, false)).toEqual({ kind: 'short', amount: 10_000 })
  })
})

describe('budgetHint', () => {
  it('shows the share of the budget after the purchase, or the overspend', () => {
    expect(budgetHint(50_000, { limit_uzs: 300_000, spent_uzs: 220_000 })).toEqual({ kind: 'budget', percent: 90 })
    expect(budgetHint(100_000, { limit_uzs: 300_000, spent_uzs: 250_000 })).toEqual({ kind: 'budgetOver', amount: 50_000 })
  })
})
