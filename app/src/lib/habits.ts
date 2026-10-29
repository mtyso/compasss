import type { Habit, HabitSchedule, HabitStreak } from '@/stores/habits'
import { weekdayNames, type Translate } from '@/lib/tasks'

/** What one tap does: a check toggles, count and limit habits add one. */
export function tapValue(habit: Pick<Habit, 'type' | 'today'>): number {
  return habit.type === 'check' ? (habit.today.value > 0 ? 0 : 1) : habit.today.value + 1
}

/** Share of the day's target, 0…1 (a limit can go past 1 once it is broken). */
export function progress(habit: Pick<Habit, 'type' | 'target' | 'today'>): number {
  if (habit.type === 'check') return habit.today.value > 0 ? 1 : 0
  if (habit.target <= 0) return habit.today.value > 0 ? 2 : 0
  return habit.today.value / habit.target
}

/** «Каждый день», «По будням», «Выходные», «Пн, ср, пт», «3 раза в неделю». */
export function scheduleLabel(schedule: HabitSchedule, t: Translate): string {
  if ('per_week' in schedule) return t('habits.schedule.perWeek', schedule.per_week)
  const days = [...schedule.days].sort((a, b) => a - b).join(',')
  if (days === '1,2,3,4,5,6,7') return t('habits.schedule.daily')
  if (days === '1,2,3,4,5') return t('habits.schedule.weekdays')
  if (days === '6,7') return t('habits.schedule.weekends')
  const names = weekdayNames(t)
  const list = schedule.days.map((d) => names[d - 1] ?? '').join(', ')
  return list.charAt(0).toUpperCase() + list.slice(1).toLowerCase()
}

/** «3 из 8 стаканов», «2 из 2 кофе», or nothing for a check habit. */
export function amountLabel(habit: Pick<Habit, 'type' | 'target' | 'unit' | 'today'>, t: Translate): string | null {
  if (habit.type === 'check') return null
  const text = t('habits.ofTarget', { value: habit.today.value, target: habit.target })
  return habit.unit ? `${text} ${habit.unit}` : text
}

/** Streak lengths worth marking (docs/PLAN.md, D37), the same as the server's. */
export const DAY_MILESTONES = [7, 14, 30, 60, 100, 200, 365]
export const WEEK_MILESTONES = [4, 8, 12, 26, 52]

/** How many kept days (weeks) in a row earn a spare, and how many can be held. */
export const SPARE_RULE = { day: { every: 7, max: 2 }, week: { every: 4, max: 1 } } as const

export function milestones(unit: HabitStreak['unit']): number[] {
  return unit === 'week' ? WEEK_MILESTONES : DAY_MILESTONES
}

/**
 * Where a streak sits on the milestone scale, 0…1. The marks (0 first) stand evenly apart, like the
 * divisions of a dial, so 7 → 14 takes as much room as 100 → 200.
 */
export function scaleShare(current: number, marks: number[]): number {
  const points = [0, ...marks]
  const index = points.findIndex((p) => p > current)
  if (index < 0) return 1
  const low = points[index - 1]!
  const high = points[index]!
  return (index - 1 + (current - low) / (high - low)) / (points.length - 1)
}

/** What a tap that grew the streak is worth marking: a milestone reached, or the record beaten. */
export function streakMoment(before: HabitStreak, after: HabitStreak): { kind: 'milestone' | 'record'; n: number } | null {
  if (after.current <= before.current) return null
  const marks = milestones(after.unit)
  if (marks.includes(after.current)) return { kind: 'milestone', n: after.current }
  // Passing the longest earlier run, once that run was a real streak.
  const record = after.previous_best
  if (before.current <= record && after.current > record && record >= marks[0]!) return { kind: 'record', n: after.current }
  return null
}

/**
 * The year map: days (from a Monday, oldest first) in columns of weeks, and the columns where a
 * month begins. A label that would crowd the next one is left out.
 */
export function yearColumns<T extends { date: string }>(days: T[]): { weeks: (T | null)[][]; months: { column: number; month: string }[] } {
  const weeks: (T | null)[][] = []
  for (let i = 0; i < days.length; i += 7) {
    const week: (T | null)[] = days.slice(i, i + 7)
    while (week.length < 7) week.push(null)
    weeks.push(week)
  }
  const months: { column: number; month: string }[] = []
  weeks.forEach((week, column) => {
    // A month is named over the first week that starts in it.
    const month = week[0]!.date.slice(0, 7)
    const previous = column > 0 ? weeks[column - 1]![0]!.date.slice(0, 7) : null
    if (month !== previous) months.push({ column, month })
  })
  return { weeks, months: months.filter((m, i) => !months[i + 1] || months[i + 1]!.column - m.column >= 3) }
}
