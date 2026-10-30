import type { Translate } from './tasks'

// The five daily prayers on the screen (docs/PLAN.md, D40): what a prayer's state lets a tap do, and the time
// left until the next one. The times and states come from the server (/api/prayer).

export type PrayerName = 'fajr' | 'dhuhr' | 'asr' | 'maghrib' | 'isha'

/** done and qaza are marked; now is on and not marked; missed is over and not marked; skipped is before marking began. */
export type PrayerState = 'done' | 'qaza' | 'now' | 'later' | 'missed' | 'skipped'

export const PRAYER_NAMES: PrayerName[] = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha']

/**
 * What a tap on a prayer does: one whose time is on is marked at once, a marked one or one past its time
 * opens the choice (in time, made up, unmark), one still ahead does nothing.
 */
export function tapAction(state: PrayerState): 'mark' | 'choose' | 'none' {
  if (state === 'now') return 'mark'
  return state === 'later' ? 'none' : 'choose'
}

/** Minutes from `now` to «HH:MM» on a date in Tashkent; negative once it has passed. */
export function minutesUntil(date: string, clock: string, now: Date = new Date()): number {
  return Math.ceil((new Date(`${date}T${clock}:00+05:00`).getTime() - now.getTime()) / 60_000)
}

/** «1 ч 12 мин», «45 мин», «2 ч». */
export function formatLeft(minutes: number, t: Translate): string {
  const m = Math.max(0, minutes)
  const hours = Math.floor(m / 60)
  const rest = m % 60
  if (hours === 0) return t('prayer.minutes', { m: rest })
  return rest === 0 ? t('prayer.hours', { h: hours }) : t('prayer.hoursMinutes', { h: hours, m: rest })
}

/** How much of a prayer's time has gone, 0…1, for the bar under the current prayer. */
export function windowShare(date: string, start: string, end: string, now: Date = new Date()): number {
  const from = new Date(`${date}T${start}:00+05:00`).getTime()
  let to = new Date(`${date}T${end}:00+05:00`).getTime()
  // Isha's time runs to Fajr, the next morning.
  if (to <= from) to += 86_400_000
  return Math.min(1, Math.max(0, (now.getTime() - from) / (to - from)))
}
