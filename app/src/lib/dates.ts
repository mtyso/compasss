import { TIME_ZONE } from './format'

function parts(date: Date): Record<string, string> {
  const formatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  })
  return Object.fromEntries(formatter.formatToParts(date).map((p) => [p.type, p.value]))
}

/** «2026-10-21» in Tashkent, whatever the device's own time zone is. */
export function isoDate(date: Date = new Date()): string {
  const p = parts(date)
  return `${p.year}-${p.month}-${p.day}`
}

/** «14:05» in Tashkent. */
export function isoTime(date: Date = new Date()): string {
  const p = parts(date)
  return `${p.hour}:${p.minute}`
}

/** «2026-10» in Tashkent. */
export function isoMonth(date: Date = new Date()): string {
  return isoDate(date).slice(0, 7)
}

export function shiftMonth(month: string, delta: number): string {
  const [year, m] = month.split('-').map(Number)
  const index = year * 12 + (m - 1) + delta
  return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`
}

export function shiftDate(day: string, delta: number): string {
  const date = new Date(`${day}T12:00:00Z`)
  date.setUTCDate(date.getUTCDate() + delta)
  return date.toISOString().slice(0, 10)
}

/** A calendar date as a Date at Tashkent noon, safe to pass to the Intl formatters. */
export function dateAtNoon(day: string): Date {
  return new Date(`${day}T12:00:00+05:00`)
}
