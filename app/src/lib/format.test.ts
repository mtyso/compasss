import { describe, expect, it } from 'vitest'
import { formatDate, formatDayHeading, formatDayMonth, formatMoney, formatMonthTitle, formatNumber } from './format'

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

describe('formatNumber', () => {
  it('drops the currency but keeps grouping and cents', () => {
    expect(formatNumber(1_250_000, 'UZS')).toBe('1\u202f250\u202f000')
    expect(formatNumber(2050, 'USD')).toBe('20,50')
    expect(formatNumber(-45_000, 'UZS')).toBe('−45\u202f000')
  })
})

describe('dates in words', () => {
  it('spells Uzbek dates out instead of trusting the browser', () => {
    expect(formatDayMonth('2026-11-10', 'uz')).toBe('10-noyabr')
    expect(formatDayHeading(new Date('2026-10-21T12:00:00+05:00'), 'uz')).toBe('Chorshanba, 21-oktabr')
    expect(formatMonthTitle('2026-10', 'uz')).toBe('Oktabr 2026')
  })

  it('uses the Tashkent calendar day for the heading', () => {
    // 20:30 UTC on Tuesday is already Wednesday in Tashkent.
    expect(formatDayHeading(new Date('2026-10-20T20:30:00Z'), 'uz')).toBe('Chorshanba, 21-oktabr')
    expect(formatDayHeading(new Date('2026-10-20T20:30:00Z'), 'ru')).toBe('Среда, 21 октября')
  })

  it('formats Russian dates with the browser', () => {
    expect(formatDayMonth('2026-11-10', 'ru')).toBe('10 ноября')
    expect(formatMonthTitle('2026-10', 'ru')).toBe('Октябрь 2026')
  })
})
