import { describe, expect, it } from 'vitest'
import { orderDebts, payoff, type DebtInput } from './debts'

const card: DebtInput = { id: 1, amount: 5_000_000, rate: 36, payment: 400_000 }
const brother: DebtInput = { id: 2, amount: 600_000, rate: null, payment: 300_000 }
const phone: DebtInput = { id: 3, amount: 2_400_000, rate: 0, payment: 200_000 }

describe('orderDebts', () => {
  it('puts the dearest first for the avalanche and the smallest for the snowball, as the server does', () => {
    expect(orderDebts([brother, phone, card], 'avalanche').map((d) => d.id)).toEqual([1, 2, 3])
    expect(orderDebts([card, phone, brother], 'snowball').map((d) => d.id)).toEqual([2, 3, 1])
  })
})

describe('payoff', () => {
  it('pays each debt on its own payment as things are now', () => {
    const now = payoff([brother, phone], 0, null)
    expect(now.order).toEqual([
      { id: 2, month: 2 },
      { id: 3, month: 12 },
    ])
    expect(now.months).toBe(12)
    expect(now.interest).toBe(0)
  })

  it('moves a paid-off debt\'s payment and the extra down the list', () => {
    // 500 000 a month in all: the brother is gone in month 2, then the phone gets all of it (2 000 000 left)
    const snowball = payoff([brother, phone], 0, 'snowball')
    expect(snowball.order).toEqual([
      { id: 2, month: 2 },
      { id: 3, month: 6 },
    ])
    // 100 000 more: the brother gets it first, the phone everything from month 2
    expect(payoff([brother, phone], 100_000, 'snowball').months).toBe(5)
  })

  it('pays less interest with the avalanche, and a debt goes sooner with the snowball', () => {
    const avalanche = payoff([card, brother, phone], 200_000, 'avalanche')
    const snowball = payoff([card, brother, phone], 200_000, 'snowball')
    const now = payoff([card, brother, phone], 0, null)
    expect(avalanche.interest).toBeLessThan(snowball.interest)
    expect(snowball.order[0].month).toBeLessThan(avalanche.order[0].month!)
    expect(avalanche.months).toBeLessThan(now.months!)
  })

  it('never pays off a debt whose payment does not cover its interest, or one with no payment', () => {
    expect(payoff([{ id: 1, amount: 5_000_000, rate: 36, payment: 100_000 }], 0, null).months).toBeNull()
    expect(payoff([card, { id: 4, amount: 300_000, rate: null, payment: 0 }], 0, null).months).toBeNull()
    // Moving the spare money pays the one without a plan too.
    expect(payoff([card, { id: 4, amount: 300_000, rate: null, payment: 0 }], 0, 'snowball').months).not.toBeNull()
  })

  it('pays this month only what is still due: a debt taken this month starts paying next month', () => {
    const fresh = { ...brother, first: 0 }
    expect(payoff([fresh], 0, null).order).toEqual([{ id: 2, month: 3 }])
    // Its payment is not there this month for the snowball to move either: the phone gets only its own.
    expect(payoff([fresh, phone], 0, 'snowball').order).toEqual([
      { id: 2, month: 3 },
      { id: 3, month: 7 },
    ])
  })
})
