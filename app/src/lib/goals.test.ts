import { describe, expect, it } from 'vitest'
import { dateAfter, daysBetween, daysSooner, forecastAt, limitChangePerDay, monthlyFor, monthsToReach, roundUp, sliderRange, statusTone } from './goals'

// The same worked examples as tests/Feature/GoalsTest.php, so the app and the server agree.

describe('monthsToReach', () => {
  it('divides a plain sum and grows a debt with its interest', () => {
    expect(monthsToReach(15_000_000, 1_500_000)).toBe(10)
    expect(monthsToReach(0, 0)).toBe(0)
    expect(monthsToReach(1_000, 0)).toBeNull()
    // 6 mln at 24% a year, 1 mln a month: a little over 6 months
    expect(monthsToReach(6_000_000, 1_000_000, 24)).toBeCloseTo(6.46, 2)
    // A payment below the interest never pays the debt off
    expect(monthsToReach(6_000_000, 100_000, 24)).toBeNull()
  })
})

describe('monthlyFor', () => {
  it('asks the annuity payment of an installment, as the server does', () => {
    expect(monthlyFor(120_000_000, 6)).toBe(20_000_000)
    expect(monthlyFor(280_000_000, 36, 24)).toBe(10_985_199)
    expect(roundUp(10_985_199, 'UZS')).toBe(10_986_000)
    expect(roundUp(12_345, 'USD')).toBe(12_400)
  })
})

describe('dates', () => {
  it('counts average months in days', () => {
    expect(dateAfter('2026-10-10', 1)).toBe('2026-11-10')
    expect(daysBetween('2026-10-10', '2027-04-01')).toBe(173)
    expect(forecastAt(15_000_000, 1_500_000, null, '2026-10-10')).toBe('2027-08-11')
    expect(forecastAt(15_000_000, 0, null, '2026-10-10')).toBeNull()
  })
})

describe('daysSooner', () => {
  it('turns a lever\'s money a month into days', () => {
    // 10 months at 1,5 mln; 2 mln a month takes 7,5 months: 2,5 months sooner
    expect(daysSooner(15_000_000, 1_500_000, 500_000)).toBe(76)
    expect(daysSooner(15_000_000, 0, 500_000)).toBe(Infinity)
  })
})

describe('limitChangePerDay', () => {
  it('spreads a bigger contribution over the days of a month', () => {
    expect(limitChangePerDay(1_667_000, 2_667_000, 'UZS', null)).toBe(-32_854)
    // $100 more a month at 12 800 som
    expect(limitChangePerDay(10_000, 20_000, 'USD', '12800.0000')).toBe(-42_053)
  })
})

describe('sliderRange', () => {
  it('starts at what the deadline needs', () => {
    expect(sliderRange(20_000_000, 20_000_000, 120_000_000, 'UZS')).toEqual({ min: 20_000_000, max: 60_000_000, step: 100_000 })
    expect(sliderRange(0, 500_000, 15_000_000, 'UZS')).toEqual({ min: 10_000, max: 1_500_000, step: 10_000 })
  })
})

describe('statusTone', () => {
  it('colours a goal by how it is doing', () => {
    expect(statusTone('behind')).toBe('habit')
    expect(statusTone('late')).toBe('danger')
    expect(statusTone('ahead')).toBe('money')
    expect(statusTone('open')).toBe('goal')
  })
})
