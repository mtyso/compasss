import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import type { Translate } from '@/lib/tasks'
import type { EventItem, RoutineBlock, RoutineKind, RoutineOccurrence } from '@/stores/calendar'
import { blockSubtitle, blocksOn, dayTotals, eventsAhead, newBlockDays, nowAndNext, reachesNextDay, timetable, totalsLine } from './routine'

const t = i18n.global.t as unknown as Translate
const today = '2026-10-23' // a Friday

afterEach(() => {
  i18n.global.locale.value = 'ru'
})

let nextId = 1

function block(start: string, end: string, days: number[], kind: RoutineKind = 'work', fields: Partial<RoutineBlock> = {}): RoutineBlock {
  const overnight = end <= start
  return {
    id: nextId++, habit_id: null, title: 'Блок', kind, start_time: start, end_time: end, days, remind: false, sort: 0, overnight, minutes: 0, ...fields,
  }
}

function occurrence(date: string, start: string, end: string, endDate = date, kind: RoutineKind = 'work'): RoutineOccurrence {
  const id = nextId++
  return { block_id: id, key: `r${id}:${date}`, date, end_date: endDate, start, end, title: kind, kind, habit_id: null, remind: false }
}

function event(fields: Partial<EventItem> = {}): EventItem {
  const id = nextId++
  const date = fields.date ?? today
  return {
    type: 'event', key: `e${id}:${date}`, date, title: 'Встреча', event_id: id, series_id: null, end_date: date,
    start: '09:00', end: '10:00', all_day: false, color: 'indigo', repeating: false, remind_before: null, notes: null, ...fields,
  }
}

const everyDay = [1, 2, 3, 4, 5, 6, 7]
const weekdays = [1, 2, 3, 4, 5]

describe('blocksOn', () => {
  it('puts the rest of last night first and the evening part last', () => {
    const sleep = block('23:00', '07:00', everyDay, 'sleep')
    const work = block('09:00', '18:00', weekdays)
    const parts = blocksOn([work, sleep], 5)
    expect(parts.map((p) => [p.block.id, p.startMin, p.endMin, p.fromPrev, p.toNext])).toEqual([
      [sleep.id, 0, 420, true, false],
      [work.id, 540, 1080, false, false],
      [sleep.id, 1380, 1440, false, true],
    ])
  })

  it('takes the night from the weekday before, also across Sunday into Monday', () => {
    const fridayNight = block('23:00', '02:00', [5], 'rest')
    expect(blocksOn([fridayNight], 6)).toMatchObject([{ startMin: 0, endMin: 120, fromPrev: true }])
    expect(blocksOn([fridayNight], 7)).toEqual([])
    const sundayNight = block('22:00', '06:00', [7], 'sleep')
    expect(blocksOn([sundayNight], 1)).toMatchObject([{ startMin: 0, endMin: 360, fromPrev: true }])
  })

  it('ends a block at 00:00 with its own day', () => {
    const evening = block('22:00', '00:00', everyDay, 'rest')
    expect(reachesNextDay(evening)).toBe(false)
    expect(blocksOn([evening], 3)).toEqual([expect.objectContaining({ startMin: 1320, endMin: 1440, fromPrev: false, toNext: false })])
  })

  it('leaves out other weekdays', () => {
    expect(blocksOn([block('19:00', '20:00', [1, 3, 5], 'sport')], 2)).toEqual([])
  })
})

describe('timetable', () => {
  it('puts free time of 30 minutes or more between blocks, never before the first or after the last', () => {
    const parts = blocksOn([block('23:00', '07:00', everyDay, 'sleep'), block('09:00', '18:00', weekdays), block('18:10', '19:00', weekdays, 'road')], 5)
    const rows = timetable(parts)
    expect(rows.map((r) => (r.type === 'free' ? `free ${r.startMin}-${r.endMin}` : `${r.part.block.kind} ${r.part.startMin}`))).toEqual([
      'sleep 0',
      'free 420-540',
      'work 540',
      // 18:00–18:10 is too short to mention.
      'road 1090',
      'free 1140-1380',
      'sleep 1380',
    ])
  })

  it('counts a block nested in another as covered, not as a gap', () => {
    const rows = timetable(blocksOn([block('09:00', '18:00', weekdays), block('13:00', '14:00', weekdays, 'meal'), block('19:00', '20:00', weekdays, 'sport')], 1))
    expect(rows.filter((r) => r.type === 'free')).toEqual([{ type: 'free', key: 'free1080', startMin: 1080, endMin: 1140 }])
  })

  it('keys the two parts of one overnight block apart', () => {
    const rows = timetable(blocksOn([block('23:00', '07:00', everyDay, 'sleep')], 2))
    const keys = rows.map((r) => r.key)
    expect(new Set(keys).size).toBe(keys.length)
  })
})

describe('dayTotals', () => {
  it('sums each kind within the day, in the order of the kinds, and the free time', () => {
    const parts = blocksOn([block('09:00', '18:00', weekdays), block('23:00', '07:00', everyDay, 'sleep'), block('13:00', '14:00', weekdays, 'meal')], 5)
    const totals = dayTotals(parts)
    expect(totals.kinds).toEqual([
      { kind: 'sleep', minutes: 480 },
      { kind: 'work', minutes: 540 },
      { kind: 'meal', minutes: 60 },
    ])
    expect(totals.free).toBe(1440 - 480 - 540)
    expect(totalsLine(totals, t)).toBe('Сон 8 ч · Работа 9 ч · Еда 1 ч · Свободно 7 ч')
  })

  it('counts overlapping blocks of one kind once', () => {
    const totals = dayTotals(blocksOn([block('09:00', '13:00', [1]), block('12:00', '18:00', [1])], 1))
    expect(totals.kinds).toEqual([{ kind: 'work', minutes: 540 }])
  })

  it('reads in Uzbek too', () => {
    i18n.global.locale.value = 'uz'
    const totals = dayTotals(blocksOn([block('19:00', '20:30', [1], 'sport')], 1))
    expect(totalsLine(totals, t)).toBe("Sport 1 soat 30 daqiqa · Bo'sh vaqt 22 soat 30 daqiqa")
  })
})

describe('blockSubtitle', () => {
  it('names the time, the days and the habit', () => {
    const [work] = blocksOn([block('09:00', '18:00', weekdays)], 1)
    expect(blockSubtitle(work!, t)).toBe('09:00–18:00 · По будням')
    const [sport] = blocksOn([block('19:00', '20:00', [1, 3, 5], 'sport')], 1)
    expect(blockSubtitle(sport!, t, 'Спорт')).toBe('19:00–20:00 · Пн, ср, пт · привычка Спорт')
  })

  it('shows only the end for the rest of a night', () => {
    const [night] = blocksOn([block('23:00', '07:00', everyDay, 'sleep')], 1)
    expect(blockSubtitle(night!, t)).toBe('до 07:00 · Каждый день')
  })
})

describe('newBlockDays', () => {
  it('starts every day, or the weekday picked when it is not today', () => {
    expect(newBlockDays(5, 5)).toEqual(everyDay)
    expect(newBlockDays(2, 5)).toEqual([2])
  })
})

describe('nowAndNext', () => {
  const sleepLastNight = occurrence('2026-10-22', '23:00', '07:00', today, 'sleep')
  const work = occurrence(today, '09:00', '18:00')
  const lunch = occurrence(today, '13:00', '14:00', today, 'meal')
  const sport = occurrence(today, '19:00', '20:00', today, 'sport')
  const sleep = occurrence(today, '23:00', '07:00', '2026-10-24', 'sleep')
  const routine = [sleepLastNight, work, lunch, sport, sleep]

  it('finds the night from yesterday in the morning', () => {
    expect(nowAndNext(routine, today, 6 * 60)).toEqual({ current: sleepLastNight, next: work })
  })

  it('takes the block nested inside another', () => {
    expect(nowAndNext(routine, today, 13 * 60 + 30)).toEqual({ current: lunch, next: sport })
    expect(nowAndNext(routine, today, 14 * 60)).toEqual({ current: work, next: sport })
  })

  it('has nothing on in a gap, and nothing next after the last start', () => {
    expect(nowAndNext(routine, today, 8 * 60)).toEqual({ current: null, next: work })
    expect(nowAndNext(routine, today, 23 * 60 + 30)).toEqual({ current: sleep, next: null })
    expect(nowAndNext([], today, 600)).toEqual({ current: null, next: null })
  })
})

describe('eventsAhead', () => {
  it('keeps what is not over yet: timed by start, then all day, at most three', () => {
    const past = event({ start: '08:00', end: '09:00' })
    const running = event({ start: '09:30', end: '11:00' })
    const later = event({ start: '15:00', end: '16:00' })
    const evening = event({ start: '20:00', end: '21:00' })
    const birthday = event({ start: null, end: null, all_day: true })
    expect(eventsAhead([later, birthday, past, running], today, 10 * 60)).toEqual([running, later, birthday])
    expect(eventsAhead([later, birthday, evening, running], today, 10 * 60)).toEqual([running, later, evening])
  })

  it('keeps a night event from yesterday until it ends and all-day events that cover today', () => {
    const night = event({ date: '2026-10-22', end_date: today, start: '22:00', end: '01:00' })
    const trip = event({ date: '2026-10-21', end_date: '2026-10-25', start: null, end: null, all_day: true })
    expect(eventsAhead([night, trip], today, 30)).toEqual([night, trip])
    expect(eventsAhead([night, trip], today, 90)).toEqual([trip])
  })
})
