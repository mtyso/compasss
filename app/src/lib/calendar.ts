import { shiftDate, shiftMonth } from '@/lib/dates'
import { formatDayMonth, formatDayMonthShort, formatMonthName, type Locale } from '@/lib/format'
import { CATEGORY_COLORS } from '@/lib/palette'
import { isoWeekday, weekdayShort, type Translate } from '@/lib/tasks'
import type { CalendarEvent, CalendarItem, CalendarView, EventInput, EventItem, HabitItem, Layer, RoutineOccurrence } from '@/stores/calendar'
import type { Habit } from '@/stores/habits'

// Pure helpers of the calendar screens (docs/PLAN.md): dates of a period, the day cut into minutes,
// overlapping blocks side by side, bars across a week row, free time between routine blocks.
// Dates are calendar dates (YYYY-MM-DD) and times «HH:MM», local to Tashkent like the feed.

export const DAY_MINUTES = 1440
/** How many days the list view shows from its anchor. */
export const LIST_DAYS = 14
/** How long a task or a habit (a moment, not a stretch) takes on the timeline. */
export const POINT_MINUTES = 30

/** «09:30» → 570. */
export function toMinutes(time: string): number {
  const [hours, minutes] = time.split(':').map(Number)
  return hours * 60 + minutes
}

/** 570 → «09:30». Whole days wrap around: 1440 is «00:00». */
export function fromMinutes(minutes: number): string {
  const m = ((Math.round(minutes) % DAY_MINUTES) + DAY_MINUTES) % DAY_MINUTES
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
}

/** The start of the slot a minute falls in: 09:44 → 09:30 with 15-minute slots. */
export function roundSlot(minutes: number, step = 15): number {
  return Math.floor(minutes / step) * step
}

/* ---------- Periods ---------- */

/** The Monday of the week a date is in. */
export function weekStart(day: string): string {
  return shiftDate(day, 1 - isoWeekday(day))
}

/** Monday to Sunday of the week a date is in. */
export function weekDays(day: string): string[] {
  const monday = weekStart(day)
  return Array.from({ length: 7 }, (_, i) => shiftDate(monday, i))
}

export function daysInMonth(month: string): number {
  const [year, m] = month.split('-').map(Number)
  return new Date(Date.UTC(year, m, 0)).getUTCDate()
}

/** The days of a month view (YYYY-MM): whole weeks, Monday first, from the week of the 1st to the week of the last day. */
export function monthGrid(month: string): string[] {
  const last = shiftDate(weekStart(`${month}-${String(daysInMonth(month)).padStart(2, '0')}`), 6)
  const days: string[] = []
  for (let day = weekStart(`${month}-01`); day <= last; day = shiftDate(day, 1)) days.push(day)
  return days
}

/** The dates a view shows around its anchor. */
export function rangeFor(view: CalendarView, anchor: string): { from: string; to: string } {
  switch (view) {
    case 'day':
      return { from: anchor, to: anchor }
    case 'week': {
      const from = weekStart(anchor)
      return { from, to: shiftDate(from, 6) }
    }
    case 'month': {
      const grid = monthGrid(anchor.slice(0, 7))
      return { from: grid[0], to: grid[grid.length - 1] }
    }
    case 'list':
      return { from: anchor, to: shiftDate(anchor, LIST_DAYS - 1) }
  }
}

/** The anchor of the period before (-1) or after (+1). A month keeps the day where it can: Jan 31 → Feb 28. */
export function shiftAnchor(view: CalendarView, anchor: string, delta: number): string {
  if (view === 'day') return shiftDate(anchor, delta)
  if (view === 'week') return shiftDate(anchor, 7 * delta)
  if (view === 'list') return shiftDate(anchor, LIST_DAYS * delta)
  const month = shiftMonth(anchor.slice(0, 7), delta)
  return `${month}-${String(Math.min(Number(anchor.slice(8, 10)), daysInMonth(month))).padStart(2, '0')}`
}

/** A week fits a wide screen; a phone starts with the day. */
export function defaultView(wide: boolean): CalendarView {
  return wide ? 'week' : 'day'
}

/* ---------- The day in minutes ---------- */

export interface Segment<T> {
  key: string
  item: T
  startMin: number
  endMin: number
  /** It began on an earlier day (drawn with a flat top). */
  fromPrev: boolean
  /** It goes on into the next day (drawn with a flat bottom). */
  toNext: boolean
}

/**
 * The minutes of `day` covered by a stretch from `start` on `date` to `end` on `endDate`. An end of
 * 00:00 on the last day is that day's midnight (the feed already moved 22:00–00:00 back onto one day).
 */
function cut(day: string, date: string, endDate: string, start: string, end: string): Omit<Segment<unknown>, 'key' | 'item'> | null {
  if (day < date || day > endDate) return null
  const startMin = day === date ? toMinutes(start) : 0
  const endMin = day === endDate && end !== '00:00' ? toMinutes(end) : DAY_MINUTES
  if (endMin <= startMin) return null
  return { startMin, endMin, fromPrev: day > date, toNext: day < endDate }
}

/**
 * Timed things of one day for the timeline: events with times, cut to the day (23:00–01:00 gives
 * 23:00–24:00 and 00:00–01:00), and tasks and habits with a time, which last POINT_MINUTES for layout.
 * The caller passes the items its layers show. Sorted by start, longer first.
 */
export function daySegments(items: CalendarItem[], day: string): Segment<CalendarItem>[] {
  const segments: Segment<CalendarItem>[] = []
  for (const item of items) {
    if (item.type === 'event') {
      if (item.all_day || !item.start || !item.end) continue
      const part = cut(day, item.date, item.end_date, item.start, item.end)
      if (part) segments.push({ key: item.key, item, ...part })
    } else if ((item.type === 'task' || item.type === 'habit') && item.start && item.date === day) {
      const startMin = toMinutes(item.start)
      segments.push({ key: item.key, item, startMin, endMin: Math.min(DAY_MINUTES, startMin + POINT_MINUTES), fromPrev: false, toNext: false })
    }
  }
  return segments.sort(byStart)
}

/** Routine occurrences cut to one day, like `daySegments`: an overnight block shows on both days. */
export function routineSegments(routine: RoutineOccurrence[], day: string): Segment<RoutineOccurrence>[] {
  const segments: Segment<RoutineOccurrence>[] = []
  for (const occurrence of routine) {
    const part = cut(day, occurrence.date, occurrence.end_date, occurrence.start, occurrence.end)
    if (part) segments.push({ key: occurrence.key, item: occurrence, ...part })
  }
  return segments.sort(byStart)
}

interface Span {
  startMin: number
  endMin: number
}

function byStart(a: Span, b: Span): number {
  return a.startMin - b.startMin || b.endMin - a.endMin
}

export interface Columns {
  /** Its column in its cluster of overlapping segments, from 0. */
  col: number
  /** How many columns the cluster has. */
  cols: number
  /** Free columns to its right it may stretch into (0: none). */
  span: number
}

/**
 * Overlapping segments side by side, as Google Calendar lays them out: by start, longer first; each
 * takes the first column free at its start; a cluster (segments linked by overlaps) shares one column
 * count. `minMinutes` is the shortest height in minutes a segment is drawn with, so that two short
 * ones drawn on top of each other count as overlapping.
 */
export function layoutColumns<T extends Span>(segments: T[], minMinutes = 0): (T & Columns)[] {
  const end = (s: Span) => Math.max(s.endMin, s.startMin + minMinutes)
  const placed: (T & Columns)[] = []
  let cluster: (T & Columns)[] = []
  let columnEnds: number[] = []
  let clusterEnd = -Infinity

  const close = () => {
    for (const s of cluster) {
      s.cols = columnEnds.length
      for (let col = s.col + 1; col < s.cols; col++) {
        if (cluster.some((o) => o.col === col && o.startMin < end(s) && s.startMin < end(o))) break
        s.span++
      }
    }
    placed.push(...cluster)
    cluster = []
    columnEnds = []
  }

  for (const segment of [...segments].sort((a, b) => a.startMin - b.startMin || end(b) - end(a))) {
    if (cluster.length && segment.startMin >= clusterEnd) {
      close()
      clusterEnd = -Infinity
    }
    let col = columnEnds.findIndex((e) => e <= segment.startMin)
    if (col < 0) col = columnEnds.length
    columnEnds[col] = end(segment)
    cluster.push({ ...segment, col, cols: 0, span: 0 })
    clusterEnd = Math.max(clusterEnd, end(segment))
  }
  close()
  return placed
}

/**
 * How deep each routine segment is nested: a block that starts inside another is one level above
 * it (lunch inside work). Returned in drawing order, the outer block before the ones inside it.
 */
export function cascade<T extends Span>(segments: T[]): (T & { level: number })[] {
  const out: (T & { level: number })[] = []
  for (const segment of [...segments].sort(byStart)) {
    let level = 0
    for (const other of out) {
      if (other.startMin <= segment.startMin && segment.startMin < other.endMin) level = Math.max(level, other.level + 1)
    }
    out.push({ ...segment, level })
  }
  return out
}

/** Free stretches of the day around the routine blocks, at least `minGap` minutes long (the start and end of the day count). */
export function freeGaps(segments: Span[], minGap = 30): Span[] {
  const gaps: Span[] = []
  let cursor = 0
  for (const s of [...segments].sort(byStart)) {
    if (s.startMin - cursor >= minGap) gaps.push({ startMin: cursor, endMin: s.startMin })
    cursor = Math.max(cursor, s.endMin)
  }
  if (DAY_MINUTES - cursor >= minGap) gaps.push({ startMin: cursor, endMin: DAY_MINUTES })
  return gaps
}

/** Minutes of the day no routine block covers. */
export function freeMinutes(segments: Span[]): number {
  return freeGaps(segments, 0).reduce((sum, gap) => sum + gap.endMin - gap.startMin, 0)
}

/* ---------- All-day things ---------- */

const LAYER_OF: Record<CalendarItem['type'], Layer> = {
  event: 'events',
  task: 'tasks',
  payment: 'payments',
  income: 'income',
  goal: 'goals',
  lent: 'debts',
  habit: 'habits',
}

/** Every layer an item can be on. */
const ITEM_LAYERS: ReadonlySet<Layer> = new Set(Object.values(LAYER_OF))

/** The layer that shows an item. */
export function layerOf(item: CalendarItem): Layer {
  return LAYER_OF[item.type]
}

/** An event without times, one day long or several. */
export function isAllDayEvent(item: CalendarItem): item is EventItem {
  return item.type === 'event' && (item.all_day || !item.start)
}

/** Money first (it is what the day is planned around), then the rest. */
const ALL_DAY_ORDER: CalendarItem['type'][] = ['income', 'payment', 'lent', 'goal', 'event', 'task']

function allDayOn(item: CalendarItem, day: string): boolean {
  if (item.type === 'event') return isAllDayEvent(item) && item.date <= day && day <= item.end_date
  if (item.type === 'task') return !item.start && item.date === day
  return item.type !== 'habit' && item.date === day
}

function compareAllDay(a: CalendarItem, b: CalendarItem): number {
  const byType = ALL_DAY_ORDER.indexOf(a.type) - ALL_DAY_ORDER.indexOf(b.type)
  if (byType) return byType
  if (a.type === 'event' && b.type === 'event') return a.date.localeCompare(b.date) || b.end_date.localeCompare(a.end_date)
  if (a.type === 'task' && b.type === 'task') return b.priority - a.priority
  return 0
}

/**
 * What goes in the all-day area of a day: events without times covering it, tasks without a time,
 * payments, paydays, goal deadlines and money promised back, on the shown layers. In the order
 * income, payment, lent, goal, event, task. Habits have their own row (`habitsFor`).
 */
export function allDayItems(items: CalendarItem[], day: string, layers: ReadonlySet<Layer>): CalendarItem[] {
  return items.filter((item) => layers.has(layerOf(item)) && allDayOn(item, day)).sort(compareAllDay)
}

/** The habits of one day. */
export function habitsFor(items: CalendarItem[], day: string): HabitItem[] {
  return items.filter((item): item is HabitItem => item.type === 'habit' && item.date === day)
}

/**
 * A habit as it stood on one day, for HabitButton, `tapValue` and HabitValueSheet, which read
 * `today`. A missed day shows (and logs) like an open one; a future one shows empty.
 */
export function habitOnDay(habit: Habit, item: HabitItem): Habit {
  const state = item.state === 'missed' || item.state === 'future' ? 'open' : item.state
  return { ...habit, today: { value: item.value, state } }
}

export interface WeekBar {
  item: CalendarItem
  /** The columns of the week row it covers, 0…6. */
  startCol: number
  endCol: number
  lane: number
  /** It began before this row / goes on after it (drawn without the rounded end). */
  cutStart: boolean
  cutEnd: boolean
}

/**
 * The all-day row of a week (week view, or a row of the month grid): events of several days as bars
 * across the days they cover, laid in lanes (by start, longer first, each in the first lane free over
 * its days); then each day's single-day all-day items (`allDayItems`) in the lanes left free.
 * `layers` filters like in `allDayItems`; left out, everything shows.
 */
export function weekBars(items: CalendarItem[], days: string[], layers: ReadonlySet<Layer> = ITEM_LAYERS): WeekBar[] {
  const first = days[0]
  const last = days[days.length - 1]
  const taken: boolean[][] = []
  const take = (startCol: number, endCol: number): number => {
    let lane = 0
    while (taken[lane]?.slice(startCol, endCol + 1).some(Boolean)) lane++
    taken[lane] ??= []
    for (let col = startCol; col <= endCol; col++) taken[lane][col] = true
    return lane
  }

  const bars: WeekBar[] = items
    .filter((item): item is EventItem => layers.has('events') && isAllDayEvent(item) && item.date < item.end_date && item.date <= last && item.end_date >= first)
    .sort((a, b) => a.date.localeCompare(b.date) || b.end_date.localeCompare(a.end_date))
    .map((item) => {
      const startCol = item.date < first ? 0 : days.indexOf(item.date)
      const endCol = item.end_date > last ? days.length - 1 : days.indexOf(item.end_date)
      return { item, startCol, endCol, lane: take(startCol, endCol), cutStart: item.date < first, cutEnd: item.end_date > last }
    })

  days.forEach((day, col) => {
    for (const item of allDayItems(items, day, layers)) {
      if (item.type === 'event' && item.date < item.end_date) continue
      bars.push({ item, startCol: col, endCol: col, lane: take(col, col), cutStart: false, cutEnd: false })
    }
  })
  return bars
}

/** For each column, how many bars do not fit in `lanes` lanes: the «ещё N» of that day. */
export function moreCounts(bars: WeekBar[], lanes: number, columns = 7): number[] {
  const counts = Array.from({ length: columns }, () => 0)
  for (const bar of bars) {
    if (bar.lane < lanes) continue
    for (let col = bar.startCol; col <= bar.endCol; col++) counts[col]++
  }
  return counts
}

/** An event with times or a task with a time that shows on `day` as a line of the month grid. */
function timedOn(item: CalendarItem, day: string): boolean {
  if (item.type === 'event') return item.date <= day && day <= item.end_date && !isAllDayEvent(item)
  return item.type === 'task' && !!item.start && item.date === day
}

/** When a timed thing starts on `day`: one that began the day before is there from midnight. */
function startOn(item: CalendarItem, day: string): string {
  return item.date < day ? '00:00' : ('start' in item && item.start) || '00:00'
}

/**
 * A week row of the month grid: the all-day bars of `weekBars`, then each day's events with times
 * and tasks with a time, by time, in the lanes that day has left free. Habits never show here.
 */
export function monthRowBars(items: CalendarItem[], days: string[], layers: ReadonlySet<Layer> = ITEM_LAYERS): WeekBar[] {
  const bars = weekBars(items, days, layers)
  const taken: boolean[][] = []
  for (const bar of bars) {
    for (let col = bar.startCol; col <= bar.endCol; col++) (taken[bar.lane] ??= [])[col] = true
  }
  days.forEach((day, col) => {
    const timed = items
      .filter((item) => layers.has(layerOf(item)) && timedOn(item, day))
      .sort((a, b) => startOn(a, day).localeCompare(startOn(b, day)))
    for (const item of timed) {
      let lane = 0
      while (taken[lane]?.[col]) lane++
      ;(taken[lane] ??= [])[col] = true
      bars.push({ item, startCol: col, endCol: col, lane, cutStart: false, cutEnd: false })
    }
  })
  return bars
}

/* ---------- Opening the timeline, creating and moving events ---------- */

/**
 * The minute the timeline opens at: half an hour before the first timed thing, or 07:00 when
 * that is earlier; on today never more than an hour and a half before now.
 */
export function startMinute(segments: Span[], nowMin: number | null = null): number {
  const first = segments.reduce((min, s) => Math.min(min, s.startMin), Infinity)
  const base = Math.min(7 * 60, first - 30)
  return Math.max(0, nowMin === null ? base : Math.max(nowMin - 90, base))
}

/** A date and time moved by some minutes, across midnight when it has to: 23:30 + 60 is 00:30 the day after. */
export function addMinutes(date: string, time: string, minutes: number): { date: string; time: string } {
  const total = toMinutes(time) + minutes
  const days = Math.floor(total / DAY_MINUTES)
  return { date: shiftDate(date, days), time: fromMinutes(total - days * DAY_MINUTES) }
}

/** The last day an event shows on: one ending at 00:00 the day after belongs to the day it started. */
function visibleEnd(startDate: string, endDate: string, endTime: string): string {
  return endTime === '00:00' && endDate > startDate ? shiftDate(endDate, -1) : endDate
}

/** A new event from `startMin` to `endMin` of a day (a tap or a drag on the grid); 24:00 is 00:00 the day after. */
export function eventDraft(day: string, startMin: number, endMin: number): EventInput {
  const end = addMinutes(day, '00:00', endMin)
  return { starts_on: day, ends_on: end.date, start_time: fromMinutes(startMin), end_time: end.time }
}

/** The day «+» makes an event on: the day shown, today when it is on screen, otherwise the first day of the week, the month or the list. */
export function newEventDay(view: CalendarView, anchor: string, today: string): string {
  if (view === 'day') return anchor
  // A month's grid starts with the end of the month before.
  if (view === 'month') return today.slice(0, 7) === anchor.slice(0, 7) ? today : `${anchor.slice(0, 7)}-01`
  const { from, to } = rangeFor(view, anchor)
  return from <= today && today <= to ? today : from
}

/** «+» on the calendar: the next whole hour today, 09:00 on another day, an hour long. */
export function nextHourDraft(day: string, today: string, nowMin: number): EventInput {
  const start = day === today ? Math.min(Math.ceil((nowMin + 1) / 60) * 60, 23 * 60) : 9 * 60
  return eventDraft(day, start, start + 60)
}

/** An event's occurrence moved (whole) or stretched (its end) on the grid. */
export interface EventShift {
  mode: 'move' | 'resize'
  days: number
  minutes: number
}

/**
 * What to send for an occurrence moved or stretched on the grid, and how the feed item looks
 * after it (shown at once, until the feed comes back). `event` is the event behind the item and
 * `date` the day this occurrence starts on; dates sent are the occurrence's own, which the server
 * takes relative to `date` for a whole series.
 */
export function shiftEvent(
  event: Pick<CalendarEvent, 'starts_on' | 'ends_on' | 'start_time' | 'end_time'>,
  date: string,
  shift: EventShift,
): { input: EventInput; patch: Pick<EventItem, 'date' | 'end_date' | 'start' | 'end'> } {
  const startTime = event.start_time ?? '00:00'
  const endTime = event.end_time ?? '00:00'
  const span = Math.round((Date.parse(event.ends_on) - Date.parse(event.starts_on)) / 86_400_000)
  const days = shift.mode === 'move' ? shift.days : 0
  const start = shift.mode === 'move' ? addMinutes(shiftDate(date, days), startTime, shift.minutes) : { date, time: startTime }
  const end = addMinutes(shiftDate(date, span + days), endTime, shift.minutes)
  const input: EventInput =
    shift.mode === 'move'
      ? { starts_on: start.date, start_time: start.time, ends_on: end.date, end_time: end.time }
      : { ends_on: end.date, end_time: end.time }
  return { input, patch: { date: start.date, end_date: visibleEnd(start.date, end.date, end.time), start: start.time, end: end.time } }
}

/** How a habit's day looks right after a tap, until the feed brings the server's word. */
export function habitAfter(item: HabitItem, value: number, today: string): Pick<HabitItem, 'value' | 'state'> {
  if (item.habit_type === 'limit') return { value, state: value > item.target ? 'over' : 'done' }
  const kept = item.habit_type === 'check' ? value > 0 : value >= item.target
  return { value, state: kept ? 'done' : item.date < today ? 'missed' : 'open' }
}

/* ---------- Words and colours ---------- */

/**
 * The title of the period on screen: day «Пт, 23 октября», week «20–26 октября» or «27 окт – 2 нояб»,
 * month and list «Октябрь». The year is added only when it is not this year.
 */
export function periodTitle(view: CalendarView, anchor: string, today: string, locale: Locale, t: Translate): string {
  const year = today.slice(0, 4)
  if (view === 'day') {
    const text = `${weekdayShort(anchor, t)}, ${formatDayMonth(anchor, locale)}`
    return anchor.startsWith(year) ? text : `${text} ${anchor.slice(0, 4)}`
  }
  if (view === 'week') {
    const from = weekStart(anchor)
    const to = shiftDate(from, 6)
    const text =
      from.slice(0, 7) === to.slice(0, 7)
        ? `${Number(from.slice(8, 10))}–${formatDayMonth(to, locale)}`
        : `${formatDayMonthShort(from, locale)} – ${formatDayMonthShort(to, locale)}`
    return from.startsWith(year) && to.startsWith(year) ? text : `${text} ${to.slice(0, 4)}`
  }
  const text = formatMonthName(anchor.slice(0, 7), locale)
  return anchor.startsWith(year) ? text : `${text} ${anchor.slice(0, 4)}`
}

/** «8 ч», «1 ч 30 мин», «45 мин». */
export function durationLabel(minutes: number, t: Translate): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (!h) return t('routine.minutes', { m })
  return m ? t('routine.hoursMinutes', { h, m }) : t('routine.hours', { h })
}

/** The colours an event can have, in the order the sheet offers them. */
export const EVENT_COLORS = ['indigo', 'blue', 'teal', 'cyan', 'green', 'amber', 'orange', 'red', 'pink', 'purple'] as const
export type EventColor = (typeof EVENT_COLORS)[number]

/**
 * The solid fill of an event block, the same in both themes: the colour darkened enough for white
 * text to keep 4.5:1 on it.
 */
export function eventFill(color: string): string {
  return `color-mix(in srgb, ${CATEGORY_COLORS[color] ?? CATEGORY_COLORS.indigo} 78%, #000)`
}
