import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useQuickAdd, type Transaction } from './money'

const coffee: Transaction = {
  id: 7, type: 'expense', account_id: 1, to_account_id: null, amount: 85_000, currency: 'UZS', to_amount: null, rate: '1.0000',
  amount_uzs: 85_000, category_id: 3, note: null, occurred_at: '2026-11-03T13:35:00+05:00', is_one_off: false, source: 'app',
  created_at: '2026-11-03T13:36:00+05:00', recurring_payment_id: null,
}

// A tap on an operation shows it; only «Изменить» opens the form (docs/PLAN.md, D42).
describe('operation sheets', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('opens an operation read-only, not in the form', () => {
    const quick = useQuickAdd()
    quick.show(coffee)
    expect(quick.viewOpen).toBe(true)
    expect(quick.viewing).toEqual(coffee)
    expect(quick.open).toBe(false)
  })

  it('hands the operation to the form on «Изменить» and closes the view', () => {
    const quick = useQuickAdd()
    quick.show(coffee)
    quick.edit()
    expect(quick.viewOpen).toBe(false)
    expect(quick.open).toBe(true)
    expect(quick.editing).toEqual(coffee)
  })

  it('opens an empty form from «+»', () => {
    const quick = useQuickAdd()
    quick.show(coffee)
    quick.viewOpen = false
    quick.start()
    expect(quick.editing).toBeNull()
  })
})
