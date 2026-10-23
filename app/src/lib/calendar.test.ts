import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import type { CalendarItem, EventItem, HabitItem, Layer, PaymentItem, RoutineOccurrence, TaskItem } from '@/stores/calendar'
import type { Habit } from '@/stores/habits'
import { CATEGORY_COLORS } from './palette'
import type { Translate } from './tasks'
import {
  addMinutes,
  allDayItems,
  cascade,
  daySegments,
  defaultView,
  durationLabel,
  EVENT_COLORS,
  eventDraft,
  eventFill,
  freeGaps,
  freeMinutes,
  fromMinutes,
  habitAfter,
  habitOnDay,
  habitsFor,
  layerOf,
  layoutColumns,
  monthGrid,
  monthRowBars,
  moreCounts,
  nextHourDraft,
  periodTitle,
  rangeFor,
  roundSlot,
  routineSegments,
  shiftAnchor,
  shiftEvent,
  startMinute,
  toMinutes,
  weekBars,
  weekDays,
  weekStart,
} from './calendar'

const t = i18n.global.t as unknown as Translate
const today = '2026-10-23' // a Friday
const all = new Set<Layer>(['events', 'routine', 'tasks', 'payments', 'income', 'goals', 'debts', 'habits'])

afterEach(() => {
  i18n.global.locale.value = 'ru'
})

let nextId = 1

function event(fields: Partial<EventItem> = {}): EventItem {
  const id = nextId++
  const date = fields.date ?? today
  return {
    type: 'event', key: `e${id}:${date}`, date, title: 'Встреча', event_id: id, series_id: null, end_date: date,
    start: '09:00', end: '10:00', all_day: false, color: 'indigo', repeating: false, remind_before: null, notes: null, ...fields,
  }
}

function allDay(date: string, endDate = date, fields: Partial<EventItem> = {}): EventItem {
  return event({ date, end_date: endDate, start: null, end: null, all_day: true, ...fields })
}

function task(fields: Partial<TaskItem> = {}): TaskItem {
  const id = nextId++
  const date = fields.date ?? today
  return { type: 'task', key: `t${id}:${date}`, date, title: 'Задача', task_id: id, start: null, priority: 0, done: false, overdue: false, projected: false, repeating: false, ...fields }
}

function payment(fields: Partial<PaymentItem> = {}): PaymentItem {
  const id = nextId++
  const date = fields.date ?? today
  return { type: 'payment', key: `p${id}:${date}`, date, title: 'Интернет', payment_id: id, amount: 150_000, currency: 'UZS', overdue: false, projected: false, paid: false, ...fields }
}

function habit(fields: Partial<HabitItem> = {}): HabitItem {
  const id = nextId++
  const date = fields.date ?? today
  return {
    type: 'habit', key: `h${id}:${date}`, date, title: 'Вода', habit_id: id, icon: null, color: 'blue', habit_type: 'count',
    value: 0, target: 8, unit: null, start: null, state: 'open', ...fields,
  }
}

function block(date: string, start: string, end: string, endDate = date, fields: Partial<RoutineOccurrence> = {}): RoutineOccurrence {
  const id = nextId++
  return { block_id: id, key: `r${id}:${date}`, date, end_date: endDate, start, end, title: 'Блок', kind: 'work', habit_id: null, remind: false, ...fields }
}

const span = (startMin: number, endMin: number, name = '') => ({ name, startMin, endMin })

describe('minutes', () => {
  it('reads and writes times of the day', () => {
    expect(toMinutes('09:30')).toBe(570)
    expect(toMinutes('00:00')).toBe(0)
    expect(fromMinutes(570)).toBe('09:30')
    expect(fromMinutes(65)).toBe('01:05')
    expect(fromMinutes(1440)).toBe('00:00')
    expect(fromMinutes(-15)).toBe('23:45')
  })

  it('rounds down to the slot', () => {
    expect(roundSlot(584)).toBe(570)
    expect(roundSlot(570)).toBe(570)
    expect(roundSlot(599, 30)).toBe(570)
  })
})

describe('periods', () => {
  it('finds the week of a date, Monday first', () => {
    expect(weekStart('2026-10-23')).toBe('2026-10-19')
    expect(weekStart('2026-10-19')).toBe('2026-10-19')
    expect(weekStart('2026-10-25')).toBe('2026-10-19')
    expect(weekDays('2026-10-23')).toEqual(['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'])
  })

  it('lays a month out in whole weeks', () => {
    // June 2026 starts on a Monday and ends on a Tuesday: five rows.
    const june = monthGrid('2026-06')
    expect(june[0]).toBe('2026-06-01')
    expect(june.at(-1)).toBe('2026-07-05')
    expect(june).toHaveLength(35)

    // November 2026 starts on a Sunday and ends on a Monday: six rows.
    const november = monthGrid('2026-11')
    expect(november[0]).toBe('2026-10-26')
    expect(november[6]).toBe('2026-11-01')
    expect(november.at(-1)).toBe('2026-12-06')
    expect(november).toHaveLength(42)

    // February 2027 starts on a Monday and has 28 days: exactly four rows.
    expect(monthGrid('2027-02')).toHaveLength(28)
    expect(monthGrid('2027-02')[0]).toBe('2027-02-01')
    expect(monthGrid('2027-02').at(-1)).toBe('2027-02-28')
  })

  it('gives the dates each view loads', () => {
    expect(rangeFor('day', today)).toEqual({ from: today, to: today })
    expect(rangeFor('week', today)).toEqual({ from: '2026-10-19', to: '2026-10-25' })
    expect(rangeFor('month', today)).toEqual({ from: '2026-09-28', to: '2026-11-01' })
    expect(rangeFor('list', today)).toEqual({ from: today, to: '2026-11-05' })
  })

  it('moves to the period before or after', () => {
    expect(shiftAnchor('day', today, 1)).toBe('2026-10-24')
    expect(shiftAnchor('week', today, -1)).toBe('2026-10-16')
    expect(shiftAnchor('list', today, 1)).toBe('2026-11-06')
    expect(shiftAnchor('month', today, 1)).toBe('2026-11-23')
    expect(shiftAnchor('month', '2027-01-31', 1)).toBe('2027-02-28')
    expect(shiftAnchor('month', '2028-01-31', 1)).toBe('2028-02-29')
    expect(shiftAnchor('month', '2027-03-31', -1)).toBe('2027-02-28')
    expect(shiftAnchor('month', '2027-01-15', -1)).toBe('2026-12-15')
  })

  it('starts with the week on a wide screen and the day on a phone', () => {
    expect(defaultView(true)).toBe('week')
    expect(defaultView(false)).toBe('day')
  })
})

describe('daySegments', () => {
  it('cuts an event across midnight into two days', () => {
    const late = event({ date: today, end_date: '2026-10-24', start: '23:00', end: '01:00' })
    expect(daySegments([late], today)).toEqual([{ key: late.key, item: late, startMin: 1380, endMin: 1440, fromPrev: false, toNext: true }])
    expect(daySegments([late], '2026-10-24')).toEqual([{ key: late.key, item: late, startMin: 0, endMin: 60, fromPrev: true, toNext: false }])
  })

  it('ends an event at 00:00 at midnight, with nothing the next day', () => {
    // 22:00–00:00: the feed already keeps it on one day.
    const evening = event({ start: '22:00', end: '00:00' })
    expect(daySegments([evening], today)).toMatchObject([{ startMin: 1320, endMin: 1440, toNext: false }])
    expect(daySegments([evening], '2026-10-24')).toEqual([])
  })

  it('fills the days in the middle of a long event', () => {
    const trip = event({ date: today, end_date: '2026-10-25', start: '18:00', end: '12:00' })
    expect(daySegments([trip], '2026-10-24')).toMatchObject([{ startMin: 0, endMin: 1440, fromPrev: true, toNext: true }])
    expect(daySegments([trip], '2026-10-25')).toMatchObject([{ startMin: 0, endMin: 720, fromPrev: true, toNext: false }])
    expect(daySegments([trip], '2026-10-26')).toEqual([])
  })

  it('gives tasks and habits with a time half an hour, and leaves out everything without one', () => {
    const call = task({ start: '14:00' })
    const late = task({ start: '23:50' })
    const sport = habit({ start: '19:00' })
    const items: CalendarItem[] = [call, late, sport, task(), habit(), allDay(today), payment(), task({ date: '2026-10-24', start: '10:00' })]
    expect(daySegments(items, today).map((s) => [s.key, s.startMin, s.endMin])).toEqual([
      [call.key, 840, 870],
      [sport.key, 1140, 1170],
      [late.key, 1430, 1440],
    ])
  })

  it('sorts by start, longer first', () => {
    const short = event({ start: '09:00', end: '09:30' })
    const long = event({ start: '09:00', end: '11:00' })
    const early = event({ start: '08:00', end: '08:30' })
    expect(daySegments([short, long, early], today).map((s) => s.item)).toEqual([early, long, short])
  })
})

describe('routineSegments', () => {
  it('shows an overnight block on the evening it starts and the morning it ends', () => {
    const sleep = block('2026-10-22', '23:00', '07:00', today, { kind: 'sleep' })
    expect(routineSegments([sleep], '2026-10-22')).toMatchObject([{ startMin: 1380, endMin: 1440, toNext: true }])
    expect(routineSegments([sleep], today)).toMatchObject([{ key: sleep.key, startMin: 0, endMin: 420, fromPrev: true, toNext: false }])
  })

  it('keeps a block ending at 00:00 on its own day', () => {
    const evening = block(today, '22:00', '00:00')
    expect(routineSegments([evening], today)).toMatchObject([{ startMin: 1320, endMin: 1440, toNext: false }])
    expect(routineSegments([evening], '2026-10-24')).toEqual([])
  })
})

describe('layoutColumns', () => {
  const columns = (laid: { name: string; col: number; cols: number; span: number }[]) =>
    Object.fromEntries(laid.map((s) => [s.name, [s.col, s.cols, s.span]]))

  it('puts three overlapping side by side and lets a later one stretch into free columns', () => {
    const laid = layoutColumns([
      span(540, 660, 'a'), // 09:00–11:00
      span(540, 600, 'b'), // 09:00–10:00
      span(570, 630, 'c'), // 09:30–10:30
      span(600, 630, 'd'), // 10:00–10:30, where b ended
      span(645, 690, 'e'), // 10:45–11:30, still under a
      span(720, 780, 'f'), // 12:00–13:00, alone
    ])
    expect(columns(laid)).toEqual({
      a: [0, 3, 0],
      b: [1, 3, 0],
      c: [2, 3, 0],
      d: [1, 3, 0],
      e: [1, 3, 1],
      f: [0, 1, 0],
    })
  })

  it('keeps a chain of overlaps in one cluster', () => {
    const laid = layoutColumns([span(540, 600, 'a'), span(570, 630, 'b'), span(615, 660, 'c'), span(650, 700, 'd')])
    // a–b, b–c and c–d overlap: two columns for all four, even though a and d never meet.
    expect(columns(laid)).toEqual({ a: [0, 2, 0], b: [1, 2, 0], c: [0, 2, 0], d: [1, 2, 0] })
  })

  it('does not count touching ends as an overlap, unless they are drawn taller', () => {
    expect(columns(layoutColumns([span(540, 600, 'a'), span(600, 660, 'b')]))).toEqual({ a: [0, 1, 0], b: [0, 1, 0] })
    // Two 5-minute things drawn at least 25 minutes tall would cover each other.
    expect(columns(layoutColumns([span(540, 545, 'a'), span(550, 555, 'b')], 25))).toEqual({ a: [0, 2, 0], b: [1, 2, 0] })
  })
})

describe('cascade', () => {
  it('raises a block starting inside another by one level', () => {
    const levels = cascade([
      span(780, 840, 'lunch'),
      span(540, 1080, 'work'),
      span(1020, 1140, 'sport'),
      span(790, 800, 'call'),
      span(0, 420, 'sleep'),
    ])
    expect(levels.map((s) => [s.name, s.level])).toEqual([
      ['sleep', 0],
      ['work', 0],
      ['lunch', 1],
      ['call', 2],
      ['sport', 1],
    ])
  })

  it('puts the longer of two blocks starting together underneath', () => {
    expect(cascade([span(540, 600, 'short'), span(540, 1080, 'long')]).map((s) => [s.name, s.level])).toEqual([
      ['long', 0],
      ['short', 1],
    ])
  })
})

describe('free time', () => {
  it('finds the gaps between overlapping and overnight blocks', () => {
    const day = [span(0, 420), span(540, 1080), span(780, 840), span(1080, 1140), span(1160, 1200), span(1380, 1440)]
    expect(freeGaps(day)).toEqual([
      { startMin: 420, endMin: 540 },
      { startMin: 1200, endMin: 1380 },
    ])
    // The 20 minutes between 19:00 and 19:20 are too short to show but still free.
    expect(freeMinutes(day)).toBe(120 + 20 + 180)
  })

  it('counts the start and the end of the day', () => {
    expect(freeGaps([span(540, 1080)])).toEqual([
      { startMin: 0, endMin: 540 },
      { startMin: 1080, endMin: 1440 },
    ])
    expect(freeGaps([])).toEqual([{ startMin: 0, endMin: 1440 }])
    expect(freeMinutes([])).toBe(1440)
    expect(freeGaps([span(0, 600), span(610, 1440)])).toEqual([])
  })
})

describe('all-day things', () => {
  it('orders money first, then goals, events and tasks, and leaves out what is timed or hidden', () => {
    const items: CalendarItem[] = [
      task({ title: 'plain' }),
      task({ title: 'urgent', priority: 2 }),
      task({ title: 'timed', start: '10:00' }),
      allDay(today, today, { title: 'holiday' }),
      allDay('2026-10-21', '2026-10-25', { title: 'trip' }),
      event({ title: 'meeting' }),
      payment({ title: 'internet' }),
      { type: 'goal', key: 'g1', date: today, title: 'car', goal_id: 1, goal_type: 'buy', icon: null },
      { type: 'lent', key: 'l1', date: today, title: 'Aziz', account_id: 1, amount: 500_000, currency: 'UZS', overdue: false },
      { type: 'income', key: 'i1:2026-10-23', date: today, title: 'salary', income_id: 1, amount: 9_000_000, currency: 'UZS' },
      habit(),
      payment({ title: 'tomorrow', date: '2026-10-24' }),
    ]
    expect(allDayItems(items, today, all).map((i) => i.title)).toEqual(['salary', 'internet', 'Aziz', 'car', 'trip', 'holiday', 'urgent', 'plain'])
    expect(allDayItems(items, today, new Set<Layer>(['events', 'debts'])).map((i) => i.title)).toEqual(['Aziz', 'trip', 'holiday'])
  })

  it('finds the habits of a day and the layer of each item', () => {
    const water = habit()
    expect(habitsFor([water, habit({ date: '2026-10-24' }), task()], today)).toEqual([water])
    expect(layerOf(water)).toBe('habits')
    expect(layerOf(payment())).toBe('payments')
    expect(layerOf({ type: 'lent', key: 'l2', date: today, title: 'x', account_id: 2, amount: 1, currency: 'UZS', overdue: false })).toBe('debts')
  })

  it('shows a habit as it stood on that day', () => {
    const stored = { id: 7, type: 'count', target: 8, today: { value: 5, state: 'open' } } as Habit
    expect(habitOnDay(stored, habit({ value: 8, state: 'done' })).today).toEqual({ value: 8, state: 'done' })
    expect(habitOnDay(stored, habit({ value: 2, state: 'missed' })).today).toEqual({ value: 2, state: 'open' })
    expect(habitOnDay(stored, habit({ state: 'future' })).today).toEqual({ value: 0, state: 'open' })
    expect(habitOnDay(stored, habit()).id).toBe(7)
  })
})

describe('weekBars', () => {
  const days = weekDays(today) // Mon 19 … Sun 25 October

  it('lays bars of several days in lanes and fills the lanes left with single days', () => {
    const before = allDay('2026-10-17', '2026-10-20', { title: 'before' })
    const after = allDay('2026-10-21', '2026-10-27', { title: 'after' })
    const middle = allDay('2026-10-20', '2026-10-22', { title: 'middle' })
    const monday = allDay('2026-10-19', '2026-10-19', { title: 'monday' })
    const bill = payment({ date: '2026-10-21', title: 'bill' })
    const sunday = task({ date: '2026-10-25', title: 'sunday' })
    const items: CalendarItem[] = [sunday, bill, monday, after, middle, before, event({ date: '2026-10-22' }), allDay('2026-10-26', '2026-10-28')]

    const bars = weekBars(items, days)
    expect(bars.map((b) => [b.item.title, b.startCol, b.endCol, b.lane, b.cutStart, b.cutEnd])).toEqual([
      ['before', 0, 1, 0, true, false],
      ['middle', 1, 3, 1, false, false],
      ['after', 2, 6, 0, false, true],
      ['monday', 0, 0, 1, false, false],
      ['bill', 2, 2, 2, false, false],
      ['sunday', 6, 6, 1, false, false],
    ])
    expect(moreCounts(bars, 2)).toEqual([0, 0, 1, 0, 0, 0, 0])
    expect(moreCounts(bars, 1)).toEqual([1, 1, 2, 1, 0, 0, 1])
  })

  it('puts the longer bar first when two start together', () => {
    const short = allDay('2026-10-20', '2026-10-21', { title: 'short' })
    const long = allDay('2026-10-20', '2026-10-24', { title: 'long' })
    expect(weekBars([short, long], days).map((b) => [b.item.title, b.lane])).toEqual([
      ['long', 0],
      ['short', 1],
    ])
  })

  it('follows the layers', () => {
    const items: CalendarItem[] = [allDay('2026-10-20', '2026-10-22'), payment({ date: '2026-10-21' })]
    expect(weekBars(items, days, new Set<Layer>(['payments'])).map((b) => b.item.type)).toEqual(['payment'])
  })
})

describe('monthRowBars', () => {
  const days = weekDays(today) // Mon 19 … Sun 25 October

  it('puts timed things by time into the lanes the all-day bars leave free', () => {
    const trip = allDay('2026-10-20', '2026-10-22', { title: 'trip' })
    const bill = payment({ date: '2026-10-21', title: 'bill' })
    const late = event({ date: '2026-10-21', start: '18:00', end: '19:00', title: 'late' })
    const early = event({ date: '2026-10-21', start: '08:00', end: '09:00', title: 'early' })
    const call = task({ date: '2026-10-20', start: '10:00', title: 'call' })
    const sport = habit({ date: '2026-10-21', start: '07:00' })
    const bars = monthRowBars([late, sport, trip, early, call, bill, task({ date: '2026-10-21' })], days)
    expect(bars.filter((b) => b.startCol === b.endCol && b.startCol !== 0).map((b) => [b.item.title, b.startCol, b.lane])).toEqual([
      ['bill', 2, 1],
      ['Задача', 2, 2],
      ['call', 1, 1],
      ['early', 2, 3],
      ['late', 2, 4],
    ])
    expect(bars.some((b) => b.item.type === 'habit')).toBe(false)
  })

  it('shows an event across midnight on both days, first thing in the morning', () => {
    const night = event({ date: '2026-10-20', end_date: '2026-10-21', start: '23:00', end: '01:00', title: 'night' })
    const breakfast = event({ date: '2026-10-21', start: '08:00', end: '09:00', title: 'breakfast' })
    const bars = monthRowBars([breakfast, night], days)
    expect(bars.map((b) => [b.item.title, b.startCol, b.lane])).toEqual([
      ['night', 1, 0],
      ['night', 2, 0],
      ['breakfast', 2, 1],
    ])
  })

  it('follows the layers', () => {
    const items: CalendarItem[] = [event({ date: '2026-10-21' }), task({ date: '2026-10-21', start: '10:00' })]
    expect(monthRowBars(items, days, new Set<Layer>(['tasks'])).map((b) => b.item.type)).toEqual(['task'])
  })
})

describe('startMinute', () => {
  it('opens at 07:00, or half an hour before something earlier', () => {
    expect(startMinute([])).toBe(420)
    expect(startMinute([span(600, 660)])).toBe(420)
    expect(startMinute([span(600, 660), span(330, 360)])).toBe(300)
    expect(startMinute([span(0, 60)])).toBe(0)
  })

  it('keeps today close to now', () => {
    expect(startMinute([span(600, 660)], 14 * 60)).toBe(750)
    expect(startMinute([], 7 * 60)).toBe(420)
    expect(startMinute([span(330, 360)], 60)).toBe(300)
  })
})

describe('creating and moving events', () => {
  it('moves a time across midnight', () => {
    expect(addMinutes(today, '23:30', 60)).toEqual({ date: '2026-10-24', time: '00:30' })
    expect(addMinutes(today, '00:15', -30)).toEqual({ date: '2026-10-22', time: '23:45' })
    expect(addMinutes(today, '09:00', 0)).toEqual({ date: today, time: '09:00' })
  })

  it('drafts a new event, ending at midnight on the next day', () => {
    expect(eventDraft(today, 570, 630)).toEqual({ starts_on: today, ends_on: today, start_time: '09:30', end_time: '10:30' })
    expect(eventDraft(today, 1380, 1440)).toEqual({ starts_on: today, ends_on: '2026-10-24', start_time: '23:00', end_time: '00:00' })
  })

  it('drafts «+» at the next whole hour today and at 09:00 on another day', () => {
    expect(nextHourDraft(today, today, 14 * 60 + 23)).toMatchObject({ start_time: '15:00', end_time: '16:00' })
    expect(nextHourDraft(today, today, 15 * 60)).toMatchObject({ start_time: '16:00' })
    expect(nextHourDraft(today, today, 23 * 60 + 30)).toMatchObject({ starts_on: today, ends_on: '2026-10-24', start_time: '23:00', end_time: '00:00' })
    expect(nextHourDraft('2026-10-24', today, 14 * 60)).toMatchObject({ starts_on: '2026-10-24', start_time: '09:00', end_time: '10:00' })
  })

  const meeting = { starts_on: '2026-10-19', ends_on: '2026-10-19', start_time: '09:00', end_time: '10:00' }

  it('moves an occurrence by days and minutes', () => {
    // A weekly series that began on Monday, its Friday occurrence moved to Saturday 10:30.
    expect(shiftEvent(meeting, today, { mode: 'move', days: 1, minutes: 90 })).toEqual({
      input: { starts_on: '2026-10-24', start_time: '10:30', ends_on: '2026-10-24', end_time: '11:30' },
      patch: { date: '2026-10-24', end_date: '2026-10-24', start: '10:30', end: '11:30' },
    })
  })

  it('keeps an event that now ends at midnight on its own day', () => {
    const late = { ...meeting, start_time: '21:00', end_time: '22:00' }
    expect(shiftEvent(late, today, { mode: 'move', days: 0, minutes: 120 })).toEqual({
      input: { starts_on: today, start_time: '23:00', ends_on: '2026-10-24', end_time: '00:00' },
      patch: { date: today, end_date: today, start: '23:00', end: '00:00' },
    })
  })

  it('moves an event across midnight with its span', () => {
    const night = { starts_on: today, ends_on: '2026-10-24', start_time: '23:00', end_time: '01:00' }
    expect(shiftEvent(night, today, { mode: 'move', days: 0, minutes: -60 })).toEqual({
      input: { starts_on: today, start_time: '22:00', ends_on: '2026-10-24', end_time: '00:00' },
      patch: { date: today, end_date: today, start: '22:00', end: '00:00' },
    })
  })

  it('stretches only the end', () => {
    expect(shiftEvent(meeting, today, { mode: 'resize', days: 0, minutes: 45 })).toEqual({
      input: { ends_on: today, end_time: '10:45' },
      patch: { date: today, end_date: today, start: '09:00', end: '10:45' },
    })
  })
})

describe('habitAfter', () => {
  it('says how a tapped day looks until the feed answers', () => {
    expect(habitAfter(habit({ habit_type: 'check', target: 1 }), 1, today)).toEqual({ value: 1, state: 'done' })
    expect(habitAfter(habit({ habit_type: 'check', target: 1 }), 0, today)).toEqual({ value: 0, state: 'open' })
    expect(habitAfter(habit({ habit_type: 'check', target: 1, date: '2026-10-21' }), 0, today)).toEqual({ value: 0, state: 'missed' })
    expect(habitAfter(habit({ target: 8 }), 7, today)).toEqual({ value: 7, state: 'open' })
    expect(habitAfter(habit({ target: 8 }), 8, today)).toEqual({ value: 8, state: 'done' })
    expect(habitAfter(habit({ habit_type: 'limit', target: 2 }), 2, today)).toEqual({ value: 2, state: 'done' })
    expect(habitAfter(habit({ habit_type: 'limit', target: 2 }), 3, today)).toEqual({ value: 3, state: 'over' })
  })
})

describe('periodTitle', () => {
  it('names a day, a week and a month in Russian', () => {
    expect(periodTitle('day', today, today, 'ru', t)).toBe('Пт, 23 октября')
    expect(periodTitle('week', today, today, 'ru', t)).toBe('19–25 октября')
    expect(periodTitle('week', '2026-10-28', today, 'ru', t)).toBe('26 окт – 1 нояб')
    expect(periodTitle('month', today, today, 'ru', t)).toBe('Октябрь')
    expect(periodTitle('list', today, today, 'ru', t)).toBe('Октябрь')
  })

  it('adds the year only when it is not this year', () => {
    expect(periodTitle('day', '2027-01-05', today, 'ru', t)).toBe('Вт, 5 января 2027')
    expect(periodTitle('week', '2026-12-30', today, 'ru', t)).toBe('28 дек – 3 янв 2027')
    expect(periodTitle('month', '2027-02-10', today, 'ru', t)).toBe('Февраль 2027')
    expect(periodTitle('month', '2025-12-01', today, 'ru', t)).toBe('Декабрь 2025')
  })

  it('names them in Uzbek', () => {
    i18n.global.locale.value = 'uz'
    expect(periodTitle('day', today, today, 'uz', t)).toBe('Ju, 23-oktabr')
    expect(periodTitle('week', today, today, 'uz', t)).toBe('19–25-oktabr')
    expect(periodTitle('week', '2026-10-28', today, 'uz', t)).toBe('26-okt – 1-noy')
    expect(periodTitle('month', today, today, 'uz', t)).toBe('Oktabr')
    expect(periodTitle('day', '2027-01-05', today, 'uz', t)).toBe('Se, 5-yanvar 2027')
  })
})

describe('durationLabel', () => {
  it('says hours and minutes', () => {
    expect(durationLabel(480, t)).toBe('8 ч')
    expect(durationLabel(90, t)).toBe('1 ч 30 мин')
    expect(durationLabel(45, t)).toBe('45 мин')
    i18n.global.locale.value = 'uz'
    expect(durationLabel(90, t)).toBe('1 soat 30 daqiqa')
  })
})

describe('eventFill', () => {
  /** WCAG relative luminance of an sRGB colour given as 0…255 channels. */
  function luminance(rgb: number[]): number {
    const [r, g, b] = rgb.map((c) => {
      const s = c / 255
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
    })
    return 0.2126 * r + 0.7152 * g + 0.0722 * b
  }

  /** The colour `color-mix(in srgb, hex share%, #000)` gives. */
  function darken(hex: string, share: number): number[] {
    return [1, 3, 5].map((i) => Math.round(parseInt(hex.slice(i, i + 2), 16) * share))
  }

  it('mixes the colour with black', () => {
    expect(eventFill('teal')).toBe(`color-mix(in srgb, ${CATEGORY_COLORS.teal} 78%, #000)`)
    expect(eventFill('unknown')).toBe(eventFill('indigo'))
  })

  it('keeps white text at 4.5:1 or more on every colour the sheet offers', () => {
    const share = Number(eventFill('indigo').match(/ (\d+)%/)![1]) / 100
    for (const color of EVENT_COLORS) {
      const contrast = 1.05 / (luminance(darken(CATEGORY_COLORS[color], share)) + 0.05)
      expect(contrast, color).toBeGreaterThanOrEqual(4.5)
    }
    expect(EVENT_COLORS).toHaveLength(10)
  })
})
