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
  streak: { current: number; best: number; unit: 'day' | 'week' }
  days: HabitDay[]
}

export type HabitInput = Partial<Pick<Habit, 'name' | 'type' | 'target' | 'unit' | 'schedule' | 'color' | 'icon' | 'sort' | 'archived'>>

/** Habits (docs/PLAN.md, D22) with today's value, the last week and the streaks, as the server counts them. */
export const useHabits = defineStore('habits', () => {
  const habits = ref<Habit[]>([])
  const loaded = ref(false)

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

  async function remove(id: number): Promise<void> {
    await api(`/habits/${id}`, { method: 'DELETE' })
    habits.value = habits.value.filter((h) => h.id !== id)
  }

  async function restore(id: number): Promise<void> {
    put(await api<Habit>(`/habits/${id}/restore`, { method: 'POST' }))
  }

  function reset(): void {
    habits.value = []
    loaded.value = false
  }

  return { habits, loaded, active, archived, forToday, load, ensureLoaded, save, log, remove, restore, reset }
})
