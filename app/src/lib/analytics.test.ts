import { describe, expect, it } from 'vitest'
import { lastDay, monthStats, paceAt, peakHours, peakWeekday, runningTotals, subcategoriesOf, weekdayAverages, type AnalyticsDay } from './analytics'

const day = (n: number, expense_uzs = 0, count = expense_uzs ? 1 : 0, one_off_uzs = 0): AnalyticsDay => ({
  date: `2026-10-${String(n).padStart(2, '0')}`,
  expense_uzs,
  one_off_uzs,
  count,
})

describe('running total and pace', () => {
  const days = [day(1, 40_000), day(2), day(3, 80_000, 2), ...Array.from({ length: 28 }, (_, i) => day(i + 4))]
  const prev = { month: '2026-09', tracked: true, days: Array.from({ length: 30 }, (_, i) => ({ date: '', expense_uzs: i === 0 ? 100_000 : 10_000 })) }

  it('adds the days up', () => {
    expect(runningTotals([1, 0, 2, 3])).toEqual([1, 1, 3, 6])
  })

  it('compares with the month before by the same day', () => {
    // 120 000 by the 3rd against 120 000 in September
    expect(paceAt({ days, prev }, 3)).toEqual({ day: 3, now: 120_000, then: 120_000, diff: 0 })
    // 120 000 by the 10th against 190 000: 37% less
    expect(paceAt({ days, prev }, 10)).toEqual({ day: 10, now: 120_000, then: 190_000, diff: -37 })
    // 31 October is held against the end of a 30-day September
    expect(paceAt({ days, prev }, 31).then).toBe(390_000)
  })

  it('has nothing to compare with without a month before, or one without spending', () => {
    expect(paceAt({ days, prev: null }, 3)).toEqual({ day: 3, now: 120_000, then: null, diff: null })
    expect(paceAt({ days, prev: { ...prev, days: prev.days.map((d) => ({ ...d, expense_uzs: 0 })) } }, 3).diff).toBeNull()
  })

  it('runs to today, or to the last day of a month that is over', () => {
    expect(lastDay({ today: '2026-10-16', days })).toBe(16)
    expect(lastDay({ today: null, days })).toBe(31)
  })
})

describe('month stats', () => {
  it('counts expenses, the average one, the average day without one-offs and days without spending', () => {
    const stats = monthStats({ days: [day(1, 40_000), day(2), day(3, 1_080_000, 3, 1_000_000), day(4, 20_000)], counted_days: 4, today: null })
    expect(stats).toEqual({ count: 5, average: 228_000, perDay: 35_000, noSpend: 1, biggest: day(3, 1_080_000, 3, 1_000_000) })
  })

  it('counts days without spending from the first the app saw, today only once it is over', () => {
    // The app started on the 2nd; today is the 4th, nothing spent yet.
    const stats = monthStats({ days: [day(1), day(2), day(3, 10_000), day(4), day(5)], counted_days: 3, today: '2026-10-04' })
    expect(stats.noSpend).toBe(1)
  })

  it('is all zeros for an empty month', () => {
    expect(monthStats({ days: [day(1), day(2)], counted_days: 2, today: null })).toEqual({ count: 0, average: 0, perDay: 0, noSpend: 2, biggest: null })
  })
})

describe('the week', () => {
  const week = [
    { weekday: 6, total_uzs: 160_000, count: 3, days: 2 },
    { weekday: 1, total_uzs: 0, count: 0, days: 2 },
    { weekday: 4, total_uzs: 70_000, count: 2, days: 3 },
    { weekday: 2, total_uzs: 0, count: 0, days: 0 },
  ]

  it('averages each weekday over the ones gone by, Monday first', () => {
    expect(weekdayAverages(week).map((w) => [w.weekday, w.average])).toEqual([
      [1, 0],
      [2, 0],
      [4, 23_333],
      [6, 80_000],
    ])
  })

  it('names the weekday that stands out, and none when two are close', () => {
    expect(peakWeekday(week)?.weekday).toBe(6)
    expect(peakWeekday([...week, { weekday: 7, total_uzs: 150_000, count: 2, days: 2 }])).toBeNull()
    expect(peakWeekday([{ weekday: 1, total_uzs: 0, count: 0, days: 2 }])).toBeNull()
  })
})

describe('the day', () => {
  const hours = (spent: Record<number, number>) => Array.from({ length: 24 }, (_, hour) => ({ hour, total_uzs: spent[hour] ?? 0, count: spent[hour] ? 1 : 0 }))

  it('finds the three hours when most money goes', () => {
    expect(peakHours(hours({ 9: 40_000, 19: 60_000, 20: 80_000, 21: 20_000, 23: 30_000 }))).toEqual({ from: 19, to: 22, share: 70 })
  })

  it('looks across midnight', () => {
    expect(peakHours(hours({ 23: 50_000, 0: 50_000, 12: 10_000 }))).toEqual({ from: 23, to: 2, share: 91 })
  })

  it('says nothing without spending or when it spreads evenly', () => {
    expect(peakHours(hours({}))).toBeNull()
    expect(peakHours(hours(Object.fromEntries(Array.from({ length: 24 }, (_, h) => [h, 10_000]))))).toBeNull()
  })
})

it('splits a main category by its subcategories', () => {
  const data = {
    subcategories: [
      { category_id: 21, root_id: 2, total_uzs: 1_000_000, count: 1 },
      { category_id: 11, root_id: 1, total_uzs: 80_000, count: 1 },
      { category_id: 12, root_id: 1, total_uzs: 40_000, count: 2 },
    ],
  }
  expect(subcategoriesOf(data, 1).map((row) => row.category_id)).toEqual([11, 12])
})
