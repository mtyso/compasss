import { defineStore } from 'pinia'
import { computed, reactive, ref, shallowRef } from 'vue'
import { api } from '@/lib/api'
import type { Currency } from '@/lib/format'
import { readJson, writeJson } from '@/lib/storage'
import type { GoalType } from '@/stores/goals'
import type { HabitType } from '@/stores/habits'
import type { Priority, RepeatInput, RepeatRule } from '@/stores/tasks'

export type CalendarView = 'day' | 'week' | 'month' | 'list'

/** What the calendar can show, each switched on or off in «Что показывать». */
export type Layer = 'events' | 'routine' | 'tasks' | 'payments' | 'income' | 'goals' | 'debts' | 'habits'
export const LAYERS: Layer[] = ['events', 'routine', 'tasks', 'payments', 'income', 'goals', 'debts', 'habits']

/** Routine block kinds (server: App\Models\RoutineBlock::KINDS); icon and colour in components/calendar/routineLook.ts. */
export type RoutineKind = 'sleep' | 'work' | 'study' | 'sport' | 'meal' | 'road' | 'rest' | 'family' | 'other'
export const ROUTINE_KINDS: RoutineKind[] = ['sleep', 'work', 'study', 'sport', 'meal', 'road', 'rest', 'family', 'other']

/** Which occurrences of a repeating event a change or a delete applies to. */
export type Scope = 'all' | 'one' | 'following'

/**
 * An event (GET /events/{id}): a one-off, a repeating series, or one occurrence of a series edited
 * on its own (an exception: `series_id` and `series_date` set). Dates and times are local to Tashkent.
 */
export interface CalendarEvent {
  id: number
  series_id: number | null
  series_date: string | null
  title: string
  notes: string | null
  starts_on: string
  ends_on: string
  start_time: string | null
  end_time: string | null
  all_day: boolean
  color: string
  repeat: RepeatRule | null
  repeat_until: string | null
  skip_dates: string[]
  /** Minutes before the start; an all-day event counts from 09:00 of its day. */
  remind_before: number | null
}

interface ItemBase {
  /** Unique in the feed: «e12:2026-10-23», «t5:2026-10-24», «g3»… */
  key: string
  date: string
  title: string
}

/** One occurrence of an event. `end_date` is the last day it shows on (22:00–00:00 stays on one day). */
export interface EventItem extends ItemBase {
  type: 'event'
  event_id: number
  /** The series the event belongs to, or null. */
  series_id: number | null
  end_date: string
  start: string | null
  end: string | null
  all_day: boolean
  color: string
  /** A series, or an exception of one. */
  repeating: boolean
  remind_before: number | null
  notes: string | null
}

/** An open task on its due date, a later date of a repeating one (`projected`), or one already done. */
export interface TaskItem extends ItemBase {
  type: 'task'
  task_id: number
  start: string | null
  priority: Priority
  done: boolean
  overdue: boolean
  projected: boolean
  repeating: boolean
}

/** A recurring payment: the next one due, the months after it (`projected`), or one already paid. */
export interface PaymentItem extends ItemBase {
  type: 'payment'
  payment_id: number
  amount: number
  currency: Currency
  overdue: boolean
  projected: boolean
  paid: boolean
  /** The expense that paid it; only on paid ones. */
  transaction_id?: number
}

/** An expected payday of an income schedule. */
export interface IncomeItem extends ItemBase {
  type: 'income'
  income_id: number
  amount: number
  currency: Currency
}

/** A goal's deadline. */
export interface GoalItem extends ItemBase {
  type: 'goal'
  goal_id: number
  goal_type: GoalType
  icon: string | null
}

/** «Мне должны»: the day money lent was promised back; the title is the account's name. */
export interface LentItem extends ItemBase {
  type: 'lent'
  account_id: number
  amount: number
  currency: Currency
  overdue: boolean
}

/** How a habit stands on one day: `future` cannot be logged yet, `missed` is a past day not kept. */
export type HabitDayState = 'future' | 'done' | 'over' | 'open' | 'missed'

/** A habit on one day; only in feeds of at most 7 days. */
export interface HabitItem extends ItemBase {
  type: 'habit'
  habit_id: number
  icon: string | null
  color: string
  habit_type: HabitType
  value: number
  target: number
  unit: string | null
  /** The start of a routine block linked to the habit on that weekday, else null. */
  start: string | null
  state: HabitDayState
}

export type CalendarItem = EventItem | TaskItem | PaymentItem | IncomeItem | GoalItem | LentItem | HabitItem

/** A routine block on one day. An overnight one ends on `end_date` = the next day; ending at 00:00 keeps it on `date`. */
export interface RoutineOccurrence {
  block_id: number
  key: string
  date: string
  end_date: string
  start: string
  end: string
  title: string
  kind: RoutineKind
  habit_id: number | null
  remind: boolean
}

/** A recurring block of the day (GET /routine). An end not after the start means it ends the next day. */
export interface RoutineBlock {
  id: number
  /** Null when the linked habit is archived or deleted. */
  habit_id: number | null
  title: string
  kind: RoutineKind
  start_time: string
  end_time: string
  /** ISO weekdays it starts on, sorted. */
  days: number[]
  remind: boolean
  sort: number
  overnight: boolean
  minutes: number
}

export type RoutineInput = Partial<Pick<RoutineBlock, 'title' | 'kind' | 'start_time' | 'end_time' | 'days' | 'remind' | 'habit_id' | 'sort'>>

/** GET /calendar: what happens between `from` and `to`, with the routine behind it. */
export interface CalendarFeed {
  from: string
  to: string
  today: string
  /** «14:05» on the server when it answered. */
  now: string
  items: CalendarItem[]
  routine: RoutineOccurrence[]
  /** The events behind the `event` items, by id, so a tap opens the sheet without another request. */
  series: Record<string, CalendarEvent>
}

/** Event fields to create or change; on a change only the ones sent move. */
export interface EventInput {
  title?: string
  notes?: string | null
  starts_on?: string
  ends_on?: string
  start_time?: string | null
  end_time?: string | null
  color?: string
  repeat?: RepeatInput | null
  repeat_until?: string | null
  remind_before?: number | null
  /** scope=all only: a skipped date to bring back (the undo of deleting «Только это»). */
  unskip?: string
}

/** How to take a delete back, as `removeEvent` found it. */
export type Undo =
  | { kind: 'restore'; id: number }
  | { kind: 'unskip'; id: number; date: string }
  | { kind: 'until'; id: number; repeat_until: string | null }

/** «План дня» on «Сегодня» (GET /today `plan`). */
export interface TodayPlan {
  routine: RoutineOccurrence[]
  events: EventItem[]
}

const LAYERS_KEY = 'kompas.calendarLayers'

/** The layers this device shows; anything stored that is not a list of known layers means all of them. */
function readLayers(): Layer[] {
  const stored = readJson<unknown>(LAYERS_KEY)
  if (!Array.isArray(stored)) return [...LAYERS]
  return LAYERS.filter((layer) => stored.includes(layer))
}

function scopeQuery(scope?: Scope, date?: string): string {
  const params = new URLSearchParams()
  if (scope) params.set('scope', scope)
  if (date) params.set('date', date)
  const query = params.toString()
  return query ? `?${query}` : ''
}

/**
 * The calendar (docs/PLAN.md): feeds by date range, cached so that paging back and forth shows the
 * last known feed at once while a fresh one loads, the routine blocks, and the changes to events.
 */
export const useCalendar = defineStore('calendar', () => {
  /** Feeds by «from:to». */
  const feeds = ref(new Map<string, CalendarFeed>())
  /** The range on screen. */
  const current = ref<string | null>(null)
  /** The feed last on screen; it stays while a refresh or another range loads. */
  const shown = shallowRef<CalendarFeed | null>(null)
  const failed = ref(false)

  /** Ranges on their way, with the number of their latest request: an older answer is dropped. */
  const pending = reactive(new Map<string, number>())
  let requests = 0
  /** Counts the changes: an answer to a request made before the latest one is never cached. */
  let generation = 0

  const feed = computed<CalendarFeed | null>(() => (current.value ? feeds.value.get(current.value) : undefined) ?? shown.value)
  /** The range on screen is not in the cache yet and is on its way (`feed` may still hold the one shown before). */
  const loading = computed(() => !!current.value && pending.has(current.value) && !feeds.value.has(current.value))

  const keyOf = (from: string, to: string) => `${from}:${to}`

  async function load(key: string): Promise<void> {
    const id = ++requests
    const asked = generation
    pending.set(key, id)
    const [from, to] = key.split(':')
    let stale = false
    try {
      const data = await api<CalendarFeed>(`/calendar?from=${from}&to=${to}`)
      // A newer request for the same range made this answer stale.
      if (pending.get(key) !== id) return
      if (asked !== generation) {
        // It predates a change made while it was on its way: what is on screen (with any tap flipped on
        // it) stays until the reload below brings the changed feed; an empty screen takes it meanwhile.
        stale = true
        if (key === current.value && !shown.value) shown.value = data
        return
      }
      feeds.value.set(key, data)
      if (key === current.value) {
        shown.value = feeds.value.get(key) ?? data
        failed.value = false
      }
    } catch {
      if (pending.get(key) === id && key === current.value) failed.value = true
    } finally {
      if (pending.get(key) === id) pending.delete(key)
      if (stale && key === current.value && !pending.has(key)) await load(key)
    }
  }

  /** Puts a range on screen: a cached feed at once (refreshed quietly), otherwise it loads. */
  async function show(from: string, to: string): Promise<void> {
    const key = keyOf(from, to)
    current.value = key
    failed.value = false
    const cached = feeds.value.get(key)
    if (cached) shown.value = cached
    // A prefetch of this range is already on its way: its answer lands in the cache and on screen.
    if (pending.has(key)) return
    await load(key)
  }

  /** Loads a range into the cache (the period before or after the one on screen) without showing it. */
  async function prefetch(from: string, to: string): Promise<void> {
    const key = keyOf(from, to)
    if (feeds.value.has(key) || pending.has(key)) return
    await load(key)
  }

  /** After a change: forgets every cached feed and reloads the one on screen. */
  async function refresh(): Promise<void> {
    feeds.value.clear()
    generation++
    const key = current.value
    // Prefetches already on their way were answered before the change: their answers are dropped.
    for (const other of [...pending.keys()]) if (other !== key) pending.delete(other)
    // The range on screen already on its way loads again when that answer comes.
    if (!key || pending.has(key)) return
    await load(key)
  }

  /* ---------- Layers (a preference of this device) ---------- */

  const layers = ref(new Set<Layer>(readLayers()))

  function toggleLayer(layer: Layer): void {
    if (layers.value.has(layer)) layers.value.delete(layer)
    else layers.value.add(layer)
    writeJson(LAYERS_KEY, LAYERS.filter((l) => layers.value.has(l)))
  }

  /* ---------- Events ---------- */

  async function getEvent(id: number): Promise<CalendarEvent> {
    return api<CalendarEvent>(`/events/${id}`)
  }

  /**
   * Creates an event, or changes one: `scope` and `date` (the occurrence edited) say which occurrences
   * of a repeating event the change applies to. Returns the event the server made or changed.
   */
  async function saveEvent(input: EventInput, id?: number, scope?: Scope, date?: string): Promise<CalendarEvent> {
    const body = JSON.stringify(input)
    const event = id
      ? (await api<{ event: CalendarEvent }>(`/events/${id}${scopeQuery(scope, date)}`, { method: 'PATCH', body })).event
      : await api<CalendarEvent>('/events', { method: 'POST', body })
    void refresh()
    return event
  }

  /** The event as last seen in a feed, or from the server. */
  async function knownEvent(id: number): Promise<CalendarEvent> {
    for (const f of [shown.value, ...feeds.value.values()]) {
      const event = f?.series[id]
      if (event) return event
    }
    return getEvent(id)
  }

  /** Deletes an event, or some occurrences of a series, and says how to take it back. */
  async function removeEvent(id: number, scope: Scope, date?: string): Promise<Undo> {
    // «Это и следующие» cuts the series short; the undo needs the end it had.
    const until = scope === 'following' ? (await knownEvent(id)).repeat_until : null
    const { event } = await api<{ event: CalendarEvent | null }>(`/events/${id}${scopeQuery(scope, date)}`, { method: 'DELETE' })
    void refresh()
    if (!event) return { kind: 'restore', id }
    if (scope === 'one' && date) return { kind: 'unskip', id, date }
    return { kind: 'until', id, repeat_until: until }
  }

  async function undo(u: Undo): Promise<void> {
    if (u.kind === 'restore') await api(`/events/${u.id}/restore`, { method: 'POST' })
    else {
      const body = u.kind === 'unskip' ? { unskip: u.date } : { repeat_until: u.repeat_until }
      await api(`/events/${u.id}?scope=all`, { method: 'PATCH', body: JSON.stringify(body) })
    }
    void refresh()
  }

  /* ---------- Routine ---------- */

  const blocks = ref<RoutineBlock[]>([])
  const routineLoaded = ref(false)

  function sortBlocks(): void {
    blocks.value.sort((a, b) => a.start_time.localeCompare(b.start_time) || a.id - b.id)
  }

  function putBlock(block: RoutineBlock): void {
    const index = blocks.value.findIndex((b) => b.id === block.id)
    if (index >= 0) blocks.value.splice(index, 1, block)
    else blocks.value.push(block)
    sortBlocks()
  }

  async function loadRoutine(): Promise<void> {
    blocks.value = await api<RoutineBlock[]>('/routine')
    routineLoaded.value = true
  }

  async function ensureRoutine(): Promise<void> {
    if (!routineLoaded.value) await loadRoutine()
  }

  async function saveBlock(input: RoutineInput, id?: number): Promise<RoutineBlock> {
    const block = await api<RoutineBlock>(id ? `/routine/${id}` : '/routine', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(input) })
    putBlock(block)
    void refresh()
    return block
  }

  async function removeBlock(id: number): Promise<void> {
    await api(`/routine/${id}`, { method: 'DELETE' })
    blocks.value = blocks.value.filter((b) => b.id !== id)
    void refresh()
  }

  async function restoreBlock(id: number): Promise<RoutineBlock> {
    const block = await api<RoutineBlock>(`/routine/${id}/restore`, { method: 'POST' })
    putBlock(block)
    void refresh()
    return block
  }

  /** Signing out: the data goes, the device's preferences (layers) stay. */
  function reset(): void {
    feeds.value.clear()
    current.value = null
    shown.value = null
    failed.value = false
    pending.clear()
    generation++
    blocks.value = []
    routineLoaded.value = false
  }

  return {
    feeds,
    current,
    feed,
    loading,
    failed,
    show,
    prefetch,
    refresh,
    layers,
    toggleLayer,
    getEvent,
    saveEvent,
    removeEvent,
    undo,
    blocks,
    routineLoaded,
    loadRoutine,
    ensureRoutine,
    saveBlock,
    removeBlock,
    restoreBlock,
    reset,
  }
})
