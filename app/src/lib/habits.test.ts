import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import type { Habit } from '@/stores/habits'
import { amountLabel, progress, scheduleLabel, tapValue } from './habits'
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
