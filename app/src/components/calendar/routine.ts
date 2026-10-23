import { DAY_MINUTES, daySegments, durationLabel, freeMinutes, isAllDayEvent, routineSegments, toMinutes } from '@/lib/calendar'
import { scheduleLabel } from '@/lib/habits'
import type { Translate } from '@/lib/tasks'
import { ROUTINE_KINDS, type EventItem, type RoutineBlock, type RoutineInput, type RoutineKind, type RoutineOccurrence } from '@/stores/calendar'

// «Распорядок дня» and «План дня» (docs/PLAN.md): the blocks of one weekday as a timetable with the
// free time between them, the hours of each kind, and the block on now and next on «Сегодня».
// Times are «HH:MM» and minutes of the day, like lib/calendar.

const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7]
const WEEKDAYS = [1, 2, 3, 4, 5]

/** A block on one weekday, in minutes of that day. */
export interface DayBlock {
  block: RoutineBlock
  startMin: number
  endMin: number
  /** The morning part of a block that started the evening before («до 07:00»). */
  fromPrev: boolean
  /** It goes on past midnight. */
  toNext: boolean
}

export type TimetableRow =
  | { type: 'block'; key: string; part: DayBlock }
  | { type: 'free'; key: string; startMin: number; endMin: number }

/** A block runs into the next day when it ends before it starts; one ending at 00:00 ends with its own day. */
export function reachesNextDay(block: Pick<RoutineBlock, 'start_time' | 'end_time'>): boolean {
  return block.end_time !== '00:00' && toMinutes(block.end_time) < toMinutes(block.start_time)
}

/**
 * The blocks of an ISO weekday: the ones that start on it, and the rest of a night that began the
 * day before. Sorted by start, longer first, so that night comes first.
 */
export function blocksOn(blocks: RoutineBlock[], weekday: number): DayBlock[] {
  const previous = weekday === 1 ? 7 : weekday - 1
  const parts: DayBlock[] = []
  for (const block of blocks) {
    const overnight = reachesNextDay(block)
    if (overnight && block.days.includes(previous)) {
      parts.push({ block, startMin: 0, endMin: toMinutes(block.end_time), fromPrev: true, toNext: false })
    }
    if (block.days.includes(weekday)) {
      const endMin = overnight || block.end_time === '00:00' ? DAY_MINUTES : toMinutes(block.end_time)
      parts.push({ block, startMin: toMinutes(block.start_time), endMin, fromPrev: false, toNext: overnight })
    }
  }
  return parts.sort((a, b) => a.startMin - b.startMin || b.endMin - a.endMin)
}

/**
 * The weekday as rows: its blocks in time order with the free stretches between them of at least
 * `minGap` minutes. Only gaps between blocks: before the first and after the last there is no «free».
 */
export function timetable(parts: DayBlock[], minGap = 30): TimetableRow[] {
  const rows: TimetableRow[] = []
  let cursor: number | null = null
  for (const part of parts) {
    if (cursor !== null && part.startMin - cursor >= minGap) {
      rows.push({ type: 'free', key: `free${cursor}`, startMin: cursor, endMin: part.startMin })
    }
    rows.push({ type: 'block', key: `${part.fromPrev ? 'prev' : 'day'}${part.block.id}`, part })
    cursor = Math.max(cursor ?? 0, part.endMin)
  }
  return rows
}

export interface DayTotals {
  /** Minutes of each kind the day has, in the order of the kinds. */
  kinds: { kind: RoutineKind; minutes: number }[]
  /** Minutes no block covers. */
  free: number
}

/** How the weekday is spent: per kind (two blocks of a kind that overlap count once) and free. */
export function dayTotals(parts: DayBlock[]): DayTotals {
  const byKind = new Map<RoutineKind, DayBlock[]>()
  for (const part of parts) byKind.set(part.block.kind, [...(byKind.get(part.block.kind) ?? []), part])
  // An unknown kind (a newer server) goes last.
  const order = (kind: RoutineKind) => {
    const index = ROUTINE_KINDS.indexOf(kind)
    return index < 0 ? ROUTINE_KINDS.length : index
  }
  const kinds = [...byKind]
    .map(([kind, list]) => ({ kind, minutes: DAY_MINUTES - freeMinutes(list) }))
    .sort((a, b) => order(a.kind) - order(b.kind))
  return { kinds, free: freeMinutes(parts) }
}

/** «Сон 8 ч», «Работа 9 ч», «Свободно 5 ч»: the page keeps each part on one line. */
export function totalsParts(totals: DayTotals, t: Translate): string[] {
  return [
    ...totals.kinds.map(({ kind, minutes }) => `${t(`routine.kinds.${kind}`)} ${durationLabel(minutes, t)}`),
    t('routine.free', { time: durationLabel(totals.free, t) }),
  ]
}

/** «Сон 8 ч · Работа 9 ч · Свободно 5 ч». */
export function totalsLine(totals: DayTotals, t: Translate): string {
  return totalsParts(totals, t).join(' · ')
}

/** «09:00–18:00 · По будням · привычка Спорт»; the rest of a night is «до 07:00 · Каждый день». */
export function blockSubtitle(part: DayBlock, t: Translate, habitName?: string | null): string {
  const { block } = part
  const time = part.fromPrev ? t('routine.untilTime', { time: block.end_time }) : `${block.start_time}–${block.end_time}`
  const parts = [time, scheduleLabel({ days: block.days }, t)]
  if (habitName) parts.push(t('routine.withHabit', { name: habitName }))
  return parts.join(' · ')
}

/** The days a new block starts with: every day, or the weekday picked on the page when it is not today's. */
export function newBlockDays(picked: number, today: number): number[] {
  return picked === today ? [...EVERY_DAY] : [picked]
}

export type ExampleKey = 'sleep' | 'work' | 'lunch' | 'sport'

/** «Начать с примера»: a usual working week. Titles are `routine.examples.<example>`. */
export const EXAMPLE_BLOCKS: (Required<Pick<RoutineInput, 'kind' | 'start_time' | 'end_time' | 'days'>> & { example: ExampleKey })[] = [
  { example: 'sleep', kind: 'sleep', start_time: '23:00', end_time: '07:00', days: EVERY_DAY },
  { example: 'work', kind: 'work', start_time: '09:00', end_time: '18:00', days: WEEKDAYS },
  { example: 'lunch', kind: 'meal', start_time: '13:00', end_time: '14:00', days: WEEKDAYS },
  { example: 'sport', kind: 'sport', start_time: '19:00', end_time: '20:00', days: [1, 3, 5] },
]

/* ---------- «План дня» on «Сегодня» ---------- */

/**
 * The routine block on now (the innermost one when they nest: lunch inside work) and the next one
 * that starts later today. `routine` is the day's occurrences, the night from yesterday included.
 */
export function nowAndNext(routine: RoutineOccurrence[], day: string, nowMin: number): { current: RoutineOccurrence | null; next: RoutineOccurrence | null } {
  const segments = routineSegments(routine, day)
  let current: (typeof segments)[number] | null = null
  for (const s of segments) {
    if (s.startMin <= nowMin && nowMin < s.endMin && (!current || s.startMin > current.startMin || (s.startMin === current.startMin && s.endMin < current.endMin))) {
      current = s
    }
  }
  const next = segments.find((s) => s.startMin > nowMin) ?? null
  return { current: current?.item ?? null, next: next?.item ?? null }
}

/** Events not over yet today: the timed ones by start, then the all-day ones; at most `limit`. */
export function eventsAhead(events: EventItem[], day: string, nowMin: number, limit = 3): EventItem[] {
  const timed = daySegments(events, day)
    .filter((s) => s.endMin > nowMin)
    .map((s) => s.item as EventItem)
  const allDay = events.filter((e) => isAllDayEvent(e) && e.date <= day && day <= e.end_date)
  return [...timed, ...allDay].slice(0, limit)
}
