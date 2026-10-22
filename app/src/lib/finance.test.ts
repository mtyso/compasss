import { describe, expect, it } from 'vitest'
import { comparePurchase, depositGrowth, downPayment, loanSchedule, monthsToSave, perDay, planPurchase, summarizePurchase, type BuyOption } from './finance'

describe('loanSchedule', () => {
  it('pays an annuity the same each month, the last one evening out the rounding', () => {
    // 1,2 mln for a year at 12%: 1% a month
    const loan = loanSchedule({ principal: 1_200_000, months: 12, ratePercent: 12, kind: 'annuity' })
    expect(loan.firstPayment).toBe(106_619)
    expect(loan.lastPayment).toBe(106_614)
    expect(loan.months).toBe(12)
    expect(loan.interest).toBe(79_423)
    expect(loan.rows.at(-1)!.balance).toBe(0)
    expect(loan.rows.reduce((sum, row) => sum + row.payment, 0)).toBe(loan.totalPaid)
    expect(loan.rows[0]).toEqual({ month: 1, payment: 106_619, interest: 12_000, principal: 94_619, balance: 1_105_381 })
  })

  it('starts a differentiated loan higher, ends it lower and costs less interest', () => {
    const loan = loanSchedule({ principal: 1_200_000, months: 12, ratePercent: 12, kind: 'differentiated' })
    expect(loan.firstPayment).toBe(112_000)
    expect(loan.lastPayment).toBe(101_000)
    // 1% of 1,2 mln + 1,1 mln + … + 0,1 mln
    expect(loan.interest).toBe(78_000)
    expect(loan.rows.at(-1)!.balance).toBe(0)
  })

  it('shortens the term and saves interest with a payment on top', () => {
    const loan = loanSchedule({ principal: 1_200_000, months: 12, ratePercent: 12, kind: 'annuity', extraMonthly: 100_000 })
    expect(loan.firstPayment).toBe(206_619)
    expect(loan.months).toBe(7)
    expect(loan.lastPayment).toBe(2_728)
    expect(loan.interest).toBe(42_442)
  })

  it('agrees with the goals on an installment and splits an interest-free one evenly', () => {
    // The worked example of lib/goals.test.ts: 280 mln for 36 months at 24%
    const credit = loanSchedule({ principal: 280_000_000, months: 36, ratePercent: 24, kind: 'annuity' })
    expect(credit.firstPayment).toBe(10_985_199)
    expect(credit.interest).toBe(115_467_149)

    const free = loanSchedule({ principal: 280_000_000, months: 12, ratePercent: 0, kind: 'annuity' })
    expect(free.firstPayment).toBe(23_333_334)
    expect(free.lastPayment).toBe(23_333_326)
    expect(free.interest).toBe(0)
  })

  it('has nothing to pay without a loan', () => {
    const loan = loanSchedule({ principal: 0, months: 12, ratePercent: 24, kind: 'annuity' })
    expect(loan.rows).toEqual([])
    expect(loan.totalPaid).toBe(0)
  })
})

describe('depositGrowth', () => {
  it('compounds interest monthly, or pays it out', () => {
    const compounded = depositGrowth({ initial: 10_000_000, monthly: 0, ratePercent: 24, months: 12, capitalize: true })
    // 10 mln × 1,02¹²
    expect(compounded.total).toBe(12_682_418)
    expect(compounded.interest).toBe(2_682_418)
    expect(compounded.contributed).toBe(10_000_000)

    const paidOut = depositGrowth({ initial: 10_000_000, monthly: 0, ratePercent: 24, months: 12, capitalize: false })
    expect(paidOut.total).toBe(12_400_000)
  })

  it('adds top-ups at the end of each month', () => {
    expect(depositGrowth({ initial: 0, monthly: 20_000_000, ratePercent: 0, months: 6, capitalize: true }).total).toBe(120_000_000)
    const grown = depositGrowth({ initial: 0, monthly: 20_000_000, ratePercent: 24, months: 6, capitalize: true })
    expect(grown.total).toBe(126_162_419)
    expect(grown.rows).toHaveLength(6)
    expect(grown.rows[0]).toEqual({ month: 1, balance: 20_000_000, interest: 0 })
  })
})

describe('monthsToSave', () => {
  it('counts the months to a sum, sooner when it waits on a deposit', () => {
    expect(monthsToSave(120_000_000, 0, 20_000_000)).toBe(6)
    // Between 5 months (104 mln on the deposit) and 6 (126 mln)
    expect(monthsToSave(120_000_000, 0, 20_000_000, 24)).toBeCloseTo(5.72, 2)
    expect(monthsToSave(120_000_000, 130_000_000, 0)).toBe(0)
    expect(monthsToSave(120_000_000, 0, 0)).toBeNull()
    expect(monthsToSave(120_000_000, 0, 0, 24)).toBeNull()
    // Nothing put in, but the deposit grows by itself
    expect(monthsToSave(110_000_000, 100_000_000, 0, 24)).toBeCloseTo(4.81, 2)
  })
})

describe('purchase', () => {
  const input = { basePrice: 400_000_000, saved: 0, monthly: 20_000_000, depositRate: 0, currency: 'UZS' as const, today: '2026-10-22' }
  const cash: BuyOption = { kind: 'cash', price: 400_000_000, downPercent: 100, months: 0, ratePercent: 0 }
  const installment: BuyOption = { kind: 'installment', price: 400_000_000, downPercent: 30, months: 12, ratePercent: 0 }
  const credit: BuyOption = { kind: 'credit', price: 400_000_000, downPercent: 30, months: 36, ratePercent: 24 }

  it('rounds a down payment up as the goals do', () => {
    expect(downPayment(374_500_000, 30, 'UZS')).toBe(112_350_000)
    expect(downPayment(1_234_567, 30, 'UZS')).toBe(371_000)
    expect(downPayment(2_000_050, 30, 'USD')).toBe(600_100)
  })

  it('works out when each way buys and what it costs', () => {
    const saving = planPurchase(cash, input)
    expect(saving).toMatchObject({ down: 400_000_000, loan: 0, payment: 0, totalPaid: 400_000_000, overpay: 0, saveMonths: 20, buyOn: '2028-06-22', freeOn: '2028-06-22' })

    const free = planPurchase(installment, input)
    expect(free).toMatchObject({ down: 120_000_000, loan: 280_000_000, payment: 23_333_334, totalPaid: 400_000_000, overpay: 0, saveMonths: 6, buyOn: '2027-04-23', freeOn: '2028-04-23' })

    const loan = planPurchase(credit, input)
    expect(loan).toMatchObject({ payment: 10_985_199, totalPaid: 515_467_149, overpay: 115_467_149, buyOn: '2027-04-23', freeOn: '2030-04-23' })
  })

  it('counts a dearer installment price as overpayment', () => {
    const dear = planPurchase({ ...installment, price: 420_000_000 }, input)
    expect(dear.down).toBe(126_000_000)
    expect(dear.overpay).toBe(20_000_000)
  })

  it('marks the soonest and the cheapest, ties included', () => {
    const comparison = comparePurchase([cash, installment, credit], input)
    expect(comparison.soonest).toEqual([1, 2])
    expect(comparison.cheapest).toEqual([0, 1])
    expect(summarizePurchase(comparison)).toEqual({ kind: 'both', index: 1 })
  })

  it('weighs buying sooner against paying more, deposit interest included', () => {
    const comparison = comparePurchase([cash, credit], { ...input, depositRate: 20 })
    const [saving, loan] = comparison.plans
    // 400 mln collected on a deposit at 20% takes 17,4 months and earns 52 mln of it
    expect(saving!.saveMonths).toBeCloseTo(17.4, 1)
    expect(saving!.earned).toBe(51_912_618)
    expect(loan!.earned).toBe(4_677_308)
    expect(summarizePurchase(comparison)).toEqual({ kind: 'tradeoff', fast: 1, cheap: 0, daysSooner: 354, costsMore: 162_702_459 })
  })

  it('marks nothing when every way is the same', () => {
    const comparison = comparePurchase([installment, installment], input)
    expect(comparison.soonest).toEqual([])
    expect(comparison.cheapest).toEqual([])
    expect(summarizePurchase(comparison)).toBeNull()
  })

  it('cannot buy without putting money aside', () => {
    const plan = planPurchase(installment, { ...input, monthly: 0 })
    expect(plan.buyOn).toBeNull()
    expect(plan.freeOn).toBeNull()
    expect(comparePurchase([cash, installment], { ...input, monthly: 0 }).soonest).toEqual([])
  })
})

describe('perDay', () => {
  it('spreads a month over its days', () => {
    expect(perDay(3_043_750)).toBe(100_000)
  })
})
