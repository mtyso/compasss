import { describe, expect, it } from 'vitest'
import { formatDate, formatMoney } from './format'

describe('formatMoney', () => {
  it('formats sums without fractions and groups thousands', () => {
    expect(formatMoney(90000, 'UZS')).toBe('90 000 сум')
    expect(formatMoney(3500000, 'UZS')).toBe('3 500 000 сум')
    expect(formatMoney(500, 'UZS')).toBe('500 сум')
  })

  it('uses the Uzbek currency word for the uz locale', () => {
    expect(formatMoney(32000, 'UZS', 'uz')).toBe("32 000 so'm")
  })

  it('formats dollars from cents', () => {
    expect(formatMoney(150000, 'USD')).toBe('$1 500,00')
    expect(formatMoney(7205, 'USD')).toBe('$72,05')
    expect(formatMoney(5, 'USD')).toBe('$0,05')
  })

  it('marks negative amounts with a minus sign', () => {
    expect(formatMoney(-45000, 'UZS')).toBe('−45 000 сум')
  })
})

describe('formatDate', () => {
  it('shows the date in Tashkent time', () => {
    // 20:30 UTC is already 01:30 the next day in Tashkent (UTC+5).
    expect(formatDate('2026-10-20T20:30:00Z')).toBe('21.10.2026')
    expect(formatDate('2026-10-20T18:00:00Z')).toBe('20.10.2026')
  })
})
