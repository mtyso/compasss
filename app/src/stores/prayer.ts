import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/lib/api'
import { isoDate } from '@/lib/dates'
import type { PrayerName, PrayerState } from '@/lib/prayer'
import { useAuth } from '@/stores/auth'
import type { HabitHistory, HabitStreak } from '@/stores/habits'

export type RemindMode = 'start' | 'start_end' | 'end' | 'off'

export interface PrayerCity {
  slug: string
  name: string
  lat: number
  lon: number
  /** Magnetic declination east of true north: a phone's compass points to magnetic north. */
  declination: number
  /** Minutes after Tashkent, in the list of cities. */
  offset?: number
}

export interface PrayerDay {
  name: PrayerName
  start: string
  end: string
  state: PrayerState
}

/** Everything the «Намаз» screen and the block on «Сегодня» need (GET /api/prayer). */
export interface PrayerOverview {
  on: boolean
  city: PrayerCity
  remind: RemindMode
  mute: PrayerName[]
  date: string
  times: Record<PrayerName | 'sunrise' | 'midnight', string>
  prayers: PrayerDay[]
  /** The last seven days, today last. */
  week: { date: string; prayers: { name: PrayerName; state: PrayerState }[] }[]
  moment: {
    /** The prayer whose time is on: yesterday's Isha after midnight. */
    current: { name: PrayerName; date: string; start: string; end: string; marked: boolean } | null
    next: { name: PrayerName; date: string; start: string; in_minutes: number }
  }
  /** Days in a row with all five prayed in their time; no spare days here. */
  streak: HabitStreak
  stats: { rate: number | null; kept_days: number; prayed: number }
  /** Not marked and past their time, last 30 days, newest first: the ones to make up. */
  missed: { date: string; name: PrayerName }[]
}

export interface PrayerYear {
  days: (HabitHistory['days'][number] & { qaza: number })[]
  stats: PrayerOverview['stats']
}

/** The five daily prayers (docs/PLAN.md, D40): times from the Muslim Board's taqvim, marks, qaza and the streak. */
export const usePrayer = defineStore('prayer', () => {
  const auth = useAuth()
  const overview = ref<PrayerOverview | null>(null)
  const year = ref<PrayerYear | null>(null)
  const cities = ref<PrayerCity[]>([])

  /** null until the user answers the offer on «Сегодня». */
  const on = computed<boolean | null>(() => {
    const value = auth.user?.settings?.prayer_on
    return value === undefined || value === null ? null : Boolean(value)
  })

  // One request at a time: a screen and its clock asking together share it.
  let loading: Promise<void> | null = null
  function load(): Promise<void> {
    loading ??= api<PrayerOverview>('/prayer')
      .then((next) => {
        overview.value = next
      })
      .finally(() => {
        loading = null
      })
    return loading
  }

  async function ensureLoaded(): Promise<void> {
    if (!overview.value || overview.value.date !== isoDate()) await load()
  }

  /** The tap shows at once; the streak and the list of missed ones come back from the server. */
  async function mark(date: string, name: PrayerName, status?: 'on_time' | 'qaza'): Promise<void> {
    const before = overview.value
    if (before && date === before.date) {
      const state: PrayerState = status === 'qaza' ? 'qaza' : 'done'
      overview.value = { ...before, prayers: before.prayers.map((p) => (p.name === name ? { ...p, state } : p)) }
    }
    try {
      overview.value = await api<PrayerOverview>(`/prayer/${date}/${name}`, { method: 'PUT', body: JSON.stringify(status ? { status } : {}) })
      if (year.value) void loadYear()
    } catch (e) {
      overview.value = before
      throw e
    }
  }

  async function unmark(date: string, name: PrayerName): Promise<void> {
    overview.value = await api<PrayerOverview>(`/prayer/${date}/${name}`, { method: 'DELETE' })
    if (year.value) void loadYear()
  }

  async function loadYear(): Promise<void> {
    year.value = await api<PrayerYear>('/prayer/year')
  }

  async function loadCities(): Promise<void> {
    if (!cities.value.length) cities.value = await api<PrayerCity[]>('/prayer/cities')
  }

  /** prayer_on, prayer_city, prayer_remind, prayer_mute: saved with the user, then the day again in the new city. */
  async function saveSettings(changes: Partial<{ prayer_on: boolean; prayer_city: string; prayer_remind: RemindMode; prayer_mute: PrayerName[] }>): Promise<void> {
    const user = await api<{ settings: Record<string, unknown> }>('/me', { method: 'PATCH', body: JSON.stringify({ settings: changes }) })
    if (auth.user) auth.setUser({ ...auth.user, settings: user.settings })
    if (changes.prayer_on !== false) await load()
  }

  return { overview, year, cities, on, load, ensureLoaded, mark, unmark, loadYear, loadCities, saveSettings }
})
