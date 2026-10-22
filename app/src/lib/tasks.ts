import { formatDayMonth, type Locale } from '@/lib/format'
import { shiftDate } from '@/lib/dates'
import type { RecurringPayment } from '@/stores/plan'
import type { RepeatRule, Task } from '@/stores/tasks'

export type Translate = (key: string, params?: Record<string, unknown> | number) => string
export type TaskView = 'today' | 'week' | 'all'
export type GroupKey = 'overdue' | 'today' | 'tomorrow' | 'week' | 'later' | 'someday' | 'done'

export interface TaskGroup {
  key: GroupKey | string
  tasks: Task[]
  payments?: RecurringPayment[]
}

/** ISO weekday of a calendar date: 1 Monday … 7 Sunday. */
export function isoWeekday(day: string): number {
  return new Date(`${day}T12:00:00Z`).getUTCDay() || 7
}

/** Most urgent first within a day: priority, then the time, then the order they were added. */
export function compareTasks(a: Task, b: Task): number {
  return b.priority - a.priority || (a.due_time ?? '99').localeCompare(b.due_time ?? '99') || a.id - b.id
}

/** The section a due date belongs to in a view, or null when the view leaves it out. */
export function dueGroup(due: string | null, view: TaskView, today: string): string | null {
  const tomorrow = shiftDate(today, 1)
  const weekEnd = shiftDate(today, 6)
  if (due !== null && due < today) return 'overdue'
  if (due === today) return 'today'
  if (view === 'today') return null
  if (view === 'week') return due !== null && due <= weekEnd ? due : null
  if (due === tomorrow) return 'tomorrow'
  if (due !== null && due <= weekEnd) return 'week'
  return due !== null ? 'later' : 'someday'
}

function groupOrder(view: TaskView, today: string): string[] {
  return view === 'week'
    ? ['overdue', 'today', ...Array.from({ length: 6 }, (_, i) => shiftDate(today, i + 1))]
    : ['overdue', 'today', 'tomorrow', 'week', 'later', 'someday']
}

/**
 * Sections for the «Дела» list. Ticked-off tasks stay in their section until the list is opened
 * again (the row animates out first), except in «Все», where they gather at the bottom.
 * `justDone` keeps rows ticked in this visit in place.
 */
export function groupTasks(tasks: Task[], view: TaskView, today: string, justDone: Set<number> = new Set()): TaskGroup[] {
  const groups = new Map<string, Task[]>()
  for (const task of tasks.filter((t) => !t.done_at || justDone.has(t.id))) {
    const key = dueGroup(task.due_on, view, today)
    if (key !== null) groups.set(key, [...(groups.get(key) ?? []), task])
  }

  return groupOrder(view, today)
    .filter((key) => groups.has(key))
    .map((key) => ({ key, tasks: groups.get(key)!.sort((a, b) => (a.due_on ?? '').localeCompare(b.due_on ?? '') || compareTasks(a, b)) }))
}

/**
 * The tasks' sections with the recurring payments due in them, payments first: in «Дела» a payment is
 * one more thing to do, ticked off with «Оплачено» (docs/PLAN.md, D28).
 */
export function withPayments(groups: TaskGroup[], payments: RecurringPayment[], view: TaskView, today: string): TaskGroup[] {
  const byKey = new Map<string, RecurringPayment[]>()
  for (const payment of [...payments].filter((p) => p.active).sort((a, b) => a.next_due_on.localeCompare(b.next_due_on) || a.id - b.id)) {
    const key = dueGroup(payment.next_due_on, view, today)
    if (key !== null) byKey.set(key, [...(byKey.get(key) ?? []), payment])
  }
  const tasks = new Map(groups.map((g) => [g.key, g.tasks]))

  return groupOrder(view, today)
    .filter((key) => tasks.has(key) || byKey.has(key))
    .map((key) => ({ key, tasks: tasks.get(key) ?? [], payments: byKey.get(key) ?? [] }))
}

/** «Сегодня», «Завтра», «Вчера», «Пт, 23 октября» or nothing for a task without a date. */
export function dueLabel(task: Pick<Task, 'due_on' | 'due_time'>, today: string, t: Translate, locale: Locale): string | null {
  if (!task.due_on) return null
  const day = task.due_on === today
    ? t('things.today')
    : task.due_on === shiftDate(today, 1)
      ? t('things.tomorrow')
      : task.due_on === shiftDate(today, -1)
        ? t('things.yesterday')
        : `${weekdayShort(task.due_on, t)}, ${formatDayMonth(task.due_on, locale)}`
  return task.due_time ? `${day}, ${task.due_time}` : day
}

export function weekdayShort(day: string, t: Translate): string {
  return weekdayNames(t)[isoWeekday(day) - 1] ?? ''
}

export function weekdayNames(t: Translate): string[] {
  return t('things.weekdays').split(' ')
}

/** «Каждый день», «Каждые 3 дня», «По пн, ср, пт», «Каждый месяц, 31-го». */
export function repeatLabel(rule: RepeatRule, t: Translate): string {
  const every = Math.max(1, rule.every)
  if (rule.unit === 'week' && rule.days?.length) {
    const names = weekdayNames(t)
    const days = rule.days.map((d) => names[d - 1]?.toLowerCase()).join(', ')
    return every === 1 ? t('things.repeat.onDays', { days }) : t('things.repeat.onDaysEvery', { days, n: every })
  }
  const base = every === 1 ? t(`things.repeat.${rule.unit}`) : t(`things.repeat.${rule.unit}N`, every)
  return rule.unit === 'month' && rule.day ? t('things.repeat.monthDay', { base, day: rule.day }) : base
}

/** Section title for a group key: a fixed word, or a day of the week view. */
export function groupTitle(key: string, today: string, t: Translate, locale: Locale): string {
  if (/^\d{4}-\d{2}-\d{2}$/.test(key)) {
    return key === shiftDate(today, 1) ? t('things.tomorrow') : `${weekdayShort(key, t)}, ${formatDayMonth(key, locale)}`
  }
  return t(`tasks.groups.${key}`)
}
