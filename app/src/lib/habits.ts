import type { Habit, HabitSchedule } from '@/stores/habits'
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
