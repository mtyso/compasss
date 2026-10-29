import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import type { Habit } from '@/stores/habits'
import { amountLabel, DAY_MILESTONES, progress, scaleShare, scheduleLabel, streakMoment, tapValue, trimYear, yearColumns } from './habits'
import type { Translate } from './tasks'

const t = i18n.global.t as unknown as Translate

function habit(type: Habit['type'], value: number, target = 1, unit: string | null = null) {
  return { type, target, unit, today: { value, state: 'open' as const } }
}

describe('tapValue', () => {
  it('toggles a check habit and adds one to the others', () => {
    expect(tapValue(habit('check', 0))).toBe(1)
    expect(tapValue(habit('check', 1))).toBe(0)
    expect(tapValue(habit('count', 3, 8))).toBe(4)
    expect(tapValue(habit('limit', 2, 2))).toBe(3)
  })
})

describe('progress', () => {
  it('is the share of the target, past 1 once a limit is broken', () => {
    expect(progress(habit('check', 1))).toBe(1)
    expect(progress(habit('count', 2, 8))).toBe(0.25)
    expect(progress(habit('limit', 3, 2))).toBe(1.5)
    expect(progress(habit('limit', 0, 0))).toBe(0)
    expect(progress(habit('limit', 1, 0))).toBe(2)
  })
})

describe('scheduleLabel', () => {
  it('names the common schedules', () => {
    expect(scheduleLabel({ days: [1, 2, 3, 4, 5, 6, 7] }, t)).toBe('Каждый день')
    expect(scheduleLabel({ days: [5, 4, 3, 2, 1] }, t)).toBe('По будням')
    expect(scheduleLabel({ days: [6, 7] }, t)).toBe('По выходным')
    expect(scheduleLabel({ days: [1, 3, 5] }, t)).toBe('Пн, ср, пт')
    expect(scheduleLabel({ per_week: 3 }, t)).toBe('3 раза в неделю')
    expect(scheduleLabel({ per_week: 5 }, t)).toBe('5 раз в неделю')
  })
})

describe('amountLabel', () => {
  it('shows the value against the target with the unit', () => {
    expect(amountLabel(habit('count', 3, 8, 'стаканов'), t)).toBe('3 из 8 стаканов')
    expect(amountLabel(habit('limit', 1, 2), t)).toBe('1 из 2')
    expect(amountLabel(habit('check', 1), t)).toBeNull()
  })
})

describe('scaleShare', () => {
  it('puts the milestones evenly apart and a streak between two of them in proportion', () => {
    // 0 · 7 · 14 · 30 · 60 · 100 · 200 · 365: seven equal steps
    expect(scaleShare(0, DAY_MILESTONES)).toBe(0)
    expect(scaleShare(7, DAY_MILESTONES)).toBeCloseTo(1 / 7)
    expect(scaleShare(22, DAY_MILESTONES)).toBeCloseTo(2.5 / 7)
    expect(scaleShare(365, DAY_MILESTONES)).toBe(1)
    expect(scaleShare(500, DAY_MILESTONES)).toBe(1)
  })
})

describe('streakMoment', () => {
  const streak = (current: number, previous: number, unit: 'day' | 'week' = 'day') => ({
    current,
    best: Math.max(current, previous),
    previous_best: previous,
    unit,
    spare: 0,
    next: null,
  })

  it('marks a milestone, and passing an earlier real streak, once', () => {
    expect(streakMoment(streak(6, 0), streak(7, 0))).toEqual({ kind: 'milestone', n: 7 })
    expect(streakMoment(streak(3, 0, 'week'), streak(4, 0, 'week'))).toEqual({ kind: 'milestone', n: 4 })
    expect(streakMoment(streak(12, 12), streak(13, 12))).toEqual({ kind: 'record', n: 13 })
    // Every later day of the same run is already the record.
    expect(streakMoment(streak(13, 12), streak(15, 12))).toBeNull()
    expect(streakMoment(streak(23, 0), streak(24, 0))).toBeNull()
    // Beating a three-day run is not a record worth a word.
    expect(streakMoment(streak(3, 3), streak(4, 3))).toBeNull()
    expect(streakMoment(streak(13, 12), streak(13, 12))).toBeNull()
  })
})

describe('yearColumns', () => {
  it('puts days in weeks and names a month over its first week', () => {
    const days = Array.from({ length: 17 }, (_, i) => {
      const date = new Date(Date.UTC(2026, 8, 21 + i)).toISOString().slice(0, 10)
      return { date }
    })
    // Mondays: 21 Sep, 28 Sep, 5 Oct; the last week has three days.
    const { weeks, months } = yearColumns(days)
    expect(weeks).toHaveLength(3)
    expect(weeks[2]!.filter(Boolean)).toHaveLength(3)
    expect(weeks[2]![3]).toBeNull()
    // September is too close to October to be named as well.
    expect(months).toEqual([{ column: 2, month: '2026-10' }])
  })
})

describe('trimYear', () => {
  const year = (first: number, length = 52 * 7 + 3) => Array.from({ length }, (_, i) => ({ state: i < first ? 'none' : 'kept' }))

  it('starts at the week the habit began', () => {
    // Began on a Wednesday 30 weeks in: the map starts on that Monday.
    expect(trimYear(year(30 * 7 + 2))).toHaveLength(52 * 7 + 3 - 30 * 7)
  })

  it('keeps at least a quarter for a new habit, and the whole year for an old one', () => {
    expect(trimYear(year(52 * 7))).toHaveLength(12 * 7 + 3)
    expect(trimYear(year(0))).toHaveLength(52 * 7 + 3)
    expect(trimYear(year(400))).toHaveLength(12 * 7 + 3)
  })
})
