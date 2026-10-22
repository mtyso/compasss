import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import type { RecurringPayment } from '@/stores/plan'
import type { Task } from '@/stores/tasks'
import { compareTasks, dueLabel, groupTasks, groupTitle, isoWeekday, repeatLabel, withPayments, type Translate } from './tasks'

const t = i18n.global.t as unknown as Translate
const today = '2026-10-21' // a Wednesday

let nextId = 1
function task(fields: Partial<Task> = {}): Task {
  return { id: nextId++, title: 'Задача', notes: null, due_on: today, due_time: null, priority: 0, repeat: null, done_at: null, overdue: false, ...fields }
}

describe('isoWeekday', () => {
  it('counts Monday as 1 and Sunday as 7', () => {
    expect(isoWeekday('2026-10-19')).toBe(1)
    expect(isoWeekday(today)).toBe(3)
    expect(isoWeekday('2026-10-25')).toBe(7)
  })
})

describe('compareTasks', () => {
  it('puts the urgent first, then the earlier time, then the older task', () => {
    const plain = task()
    const urgent = task({ priority: 2 })
    const morning = task({ due_time: '09:00' })
    const evening = task({ due_time: '19:00' })
    expect([plain, evening, urgent, morning].sort(compareTasks)).toEqual([urgent, morning, evening, plain])
  })
})

describe('groupTasks', () => {
  const overdue = task({ due_on: '2026-10-19', overdue: true })
  const due = task()
  const tomorrow = task({ due_on: '2026-10-22' })
  const sunday = task({ due_on: '2026-10-25' })
  const later = task({ due_on: '2026-11-15' })
  const someday = task({ due_on: null })
  const finished = task({ done_at: '2026-10-21T08:00:00Z' })
  const all = [someday, later, sunday, tomorrow, due, overdue, finished]

  it('shows only the overdue and today on «Сегодня»', () => {
    expect(groupTasks(all, 'today', today)).toEqual([
      { key: 'overdue', tasks: [overdue] },
      { key: 'today', tasks: [due] },
    ])
  })

  it('splits the next seven days by date on «Неделя»', () => {
    expect(groupTasks(all, 'week', today).map((g) => g.key)).toEqual(['overdue', 'today', '2026-10-22', '2026-10-25'])
  })

  it('keeps every open task on «Все», undated ones last', () => {
    expect(groupTasks(all, 'all', today).map((g) => [g.key, g.tasks])).toEqual([
      ['overdue', [overdue]],
      ['today', [due]],
      ['tomorrow', [tomorrow]],
      ['week', [sunday]],
      ['later', [later]],
      ['someday', [someday]],
    ])
  })

  it('keeps a task ticked off just now in its place', () => {
    expect(groupTasks(all, 'today', today, new Set([finished.id]))[1]).toEqual({ key: 'today', tasks: [due, finished] })
  })
})

describe('dueLabel', () => {
  it('names nearby days in words and the rest by date', () => {
    expect(dueLabel({ due_on: today, due_time: null }, today, t, 'ru')).toBe('Сегодня')
    expect(dueLabel({ due_on: '2026-10-22', due_time: '09:30' }, today, t, 'ru')).toBe('Завтра, 09:30')
    expect(dueLabel({ due_on: '2026-10-20', due_time: null }, today, t, 'ru')).toBe('Вчера')
    expect(dueLabel({ due_on: '2026-10-23', due_time: null }, today, t, 'ru')).toBe('Пт, 23 октября')
    expect(dueLabel({ due_on: null, due_time: null }, today, t, 'ru')).toBeNull()
  })
})

describe('repeatLabel', () => {
  it('describes the rule in words', () => {
    expect(repeatLabel({ unit: 'day', every: 1 }, t)).toBe('Каждый день')
    expect(repeatLabel({ unit: 'day', every: 3 }, t)).toBe('Каждые 3 дня')
    expect(repeatLabel({ unit: 'week', every: 5 }, t)).toBe('Каждые 5 недель')
    expect(repeatLabel({ unit: 'week', every: 1, days: [1, 3, 5] }, t)).toBe('По пн, ср, пт')
    expect(repeatLabel({ unit: 'week', every: 2, days: [6] }, t)).toBe('Раз в 2 нед., по сб')
    expect(repeatLabel({ unit: 'month', every: 1, day: 31 }, t)).toBe('Каждый месяц, 31-го')
  })
})

describe('groupTitle', () => {
  it('uses the fixed names and dates of the week view', () => {
    expect(groupTitle('overdue', today, t, 'ru')).toBe('Просрочено')
    expect(groupTitle('2026-10-22', today, t, 'ru')).toBe('Завтра')
    expect(groupTitle('2026-10-24', today, t, 'ru')).toBe('Сб, 24 октября')
  })
})

describe('withPayments', () => {
  const payment = (id: number, next_due_on: string, active = true): RecurringPayment => ({
    id, name: 'Платёж', amount: 150_000, currency: 'UZS', category_id: 1, account_id: 1, day_of_month: 1, next_due_on, overdue: next_due_on < today, active,
  })
  const late = payment(1, '2026-10-15')
  const due = payment(2, today)
  const nextWeek = payment(3, '2026-10-25')
  const paused = payment(4, today, false)
  const nextMonth = payment(5, '2026-11-21')
  const all = [nextMonth, paused, due, nextWeek, late]

  it('puts payments into the tasks\' sections and adds the sections only they fill', () => {
    const tasks = groupTasks([task()], 'today', today)
    expect(withPayments(tasks, all, 'today', today)).toEqual([
      { key: 'overdue', tasks: [], payments: [late] },
      { key: 'today', tasks: tasks[0]!.tasks, payments: [due] },
    ])
  })

  it('shows the week ahead by day and the rest on «Все»', () => {
    expect(withPayments([], all, 'week', today).map((g) => [g.key, g.payments?.map((p) => p.id)])).toEqual([
      ['overdue', [1]],
      ['today', [2]],
      ['2026-10-25', [3]],
    ])
    expect(withPayments([], all, 'all', today).map((g) => g.key)).toEqual(['overdue', 'today', 'week', 'later'])
  })
})
