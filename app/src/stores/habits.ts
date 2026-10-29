import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/lib/api'
import { isoDate } from '@/lib/dates'

export type HabitType = 'check' | 'count' | 'limit'
export type HabitSchedule = { days: number[] } | { per_week: number }
export type HabitState = 'done' | 'open' | 'over' | 'off'

export interface HabitDay {
  date: string
  value: number
  kept: boolean
  scheduled: boolean
  /** A miss a spare day covered (docs/PLAN.md, D37). */
  spared: boolean
  paused: boolean
}

export interface HabitStreak {
  current: number
  best: number
  /** The longest run before this one: passing it is a new record. */
  previous_best: number
  unit: 'day' | 'week'
  /** Spare days (weeks) held: each covers one miss. */
  spare: number
  /** The next milestone above the current streak, null past the last one. */
  next: number | null
}

export interface Habit {
  id: number
  name: string
  type: HabitType
  target: number
  unit: string | null
  schedule: HabitSchedule
  color: string
  icon: string | null
  sort: number
  started_on: string
  archived: boolean
  today: { value: number; state: HabitState }
  week_count: number | null
  streak: HabitStreak
  days: HabitDay[]
  /** The goal this habit works towards (docs/PLAN.md, 2.6). */
  goal_id: number | null
  /** A money habit: what a kept day saves, in som. */
  saves_uzs: number | null
}

export type HabitDayState = 'kept' | 'missed' | 'spared' | 'paused' | 'open' | 'off' | 'none'

/** A habit's screen: every day of the last 53 weeks, its numbers, and the main goal's days it saved. */
export interface HabitHistory {
  habit: Habit
  days: { date: string; value: number; state: HabitDayState }[]
  stats: {
    /** Share kept over the last 30 days (4 finished weeks for a weekly habit); null with nothing to judge. */
    rate: number | null
    rate_over: '30d' | '4w'
    kept_days: number
    total_value: number | null
    saved_uzs: number | null
    streak_saved_uzs: number | null
  }
  goal: { id: number; title: string; days: number; streak_days: number } | null
}

/** A pause for all habits, both days included: a trip or an illness that streaks wait out. */
export interface HabitPause {
  id: number
  starts_on: string
  ends_on: string
}

export type HabitInput = Partial<Pick<Habit, 'name' | 'type' | 'target' | 'unit' | 'schedule' | 'color' | 'icon' | 'sort' | 'archived' | 'goal_id' | 'saves_uzs'>>

/** Habits (docs/PLAN.md, D22) with today's value, the last week and the streaks, as the server counts them. */
export const useHabits = defineStore('habits', () => {
  const habits = ref<Habit[]>([])
  const loaded = ref(false)
  const pauses = ref<HabitPause[]>([])

  const active = computed(() => habits.value.filter((h) => !h.archived))
  const archived = computed(() => habits.value.filter((h) => h.archived))
  /** The ones that are part of today: scheduled, or a weekly one still short this week. */
  const forToday = computed(() => active.value.filter((h) => h.today.state !== 'off'))

  async function load(): Promise<void> {
    habits.value = await api<Habit[]>('/habits')
    loaded.value = true
  }

  async function ensureLoaded(): Promise<void> {
    if (!loaded.value) await load()
  }

  function put(habit: Habit): void {
    const index = habits.value.findIndex((h) => h.id === habit.id)
    if (index >= 0) habits.value.splice(index, 1, habit)
    else habits.value.push(habit)
  }

  async function save(input: HabitInput, id?: number): Promise<Habit> {
    const habit = await api<Habit>(id ? `/habits/${id}` : '/habits', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(input) })
    put(habit)
    return habit
  }

  /**
   * Sets today's (or another day's) value. Today's number changes at once so the tap feels
   * instant; the streak and the state come back from the server.
   */
  async function log(habit: Habit, value: number, date = isoDate()): Promise<Habit> {
    if (date === isoDate()) put({ ...habit, today: { ...habit.today, value } })
    try {
      const fresh = await api<Habit>(`/habits/${habit.id}/logs/${date}`, { method: 'PUT', body: JSON.stringify({ value }) })
      put(fresh)
      return fresh
    } catch (e) {
      put(habit)
      throw e
    }
  }

  async function history(id: number): Promise<HabitHistory> {
    const result = await api<HabitHistory>(`/habits/${id}/history`)
    if (loaded.value) put(result.habit)
    return result
  }

  /** The pause under way today, and the next one ahead. */
  const activePause = computed(() => pauses.value.find((p) => p.starts_on <= isoDate() && p.ends_on >= isoDate()) ?? null)
  const nextPause = computed(() => pauses.value.find((p) => p.starts_on > isoDate()) ?? null)

  async function loadPauses(): Promise<void> {
    pauses.value = await api<HabitPause[]>('/habit-pauses')
  }

  /** Days in a pause change what is due today and every streak, so the habits come back fresh. */
  async function savePause(input: Partial<Omit<HabitPause, 'id'>>, id?: number): Promise<void> {
    await api<HabitPause>(id ? `/habit-pauses/${id}` : '/habit-pauses', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(input) })
    await Promise.all([loadPauses(), load()])
  }

  async function removePause(id: number): Promise<void> {
    await api(`/habit-pauses/${id}`, { method: 'DELETE' })
    await Promise.all([loadPauses(), load()])
  }

  async function remove(id: number): Promise<void> {
    await api(`/habits/${id}`, { method: 'DELETE' })
    habits.value = habits.value.filter((h) => h.id !== id)
  }

  async function restore(id: number): Promise<void> {
    put(await api<Habit>(`/habits/${id}/restore`, { method: 'POST' }))
  }

  function reset(): void {
    habits.value = []
    pauses.value = []
    loaded.value = false
  }

  return {
    habits,
    loaded,
    active,
    archived,
    forToday,
    pauses,
    activePause,
    nextPause,
    load,
    ensureLoaded,
    save,
    log,
    history,
    loadPauses,
    savePause,
    removePause,
    remove,
    restore,
    reset,
  }
})
