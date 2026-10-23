import { onBeforeUnmount, ref } from 'vue'
import { DAY_MINUTES, roundSlot, type EventShift } from '@/lib/calendar'
import type { EventItem } from '@/stores/calendar'

/** A drag in progress on a timeline, in 15-minute steps. */
export type DragState =
  | { mode: 'create'; col: number; startMin: number; endMin: number }
  | {
      mode: 'move' | 'resize'
      key: string
      /** Columns (days) and minutes moved so far. */
      days: number
      minutes: number
      /** The same in px, for the block's translate. */
      dx: number
      dy: number
    }

/** An event block let go somewhere else, or with another end. */
export interface DragDrop extends EventShift {
  item: EventItem
}

interface Options {
  /** Drags are on: a wide screen. */
  enabled: () => boolean
  pxPerMin: () => number
  /** The things lane of each day column, left to right. */
  columns: () => (HTMLElement | null)[]
  create: (col: number, startMin: number, endMin: number) => void
  drop: (drop: DragDrop) => void
}

const STEP = 15
/** How far the mouse moves before a press becomes a drag (a shorter one stays a click). */
const THRESHOLD = 4

/**
 * Mouse drags on the day and week timelines (desktop only; a phone taps). On empty space a drag
 * selects a range for a new event; on an event block it moves the block, across days in the week;
 * from the block's bottom edge it stretches the end. While dragging only the block's transform
 * changes; the drop goes to `drop`, which keeps the block in its new place until the feed answers.
 * Esc cancels a drag.
 */
export function useCalendarDrag(options: Options) {
  const state = ref<DragState | null>(null)
  let stop: (() => void) | null = null

  function accepts(event: PointerEvent): boolean {
    return event.pointerType === 'mouse' && event.button === 0 && !state.value && !stop && options.enabled()
  }

  /** The minute of the day under the pointer, in a column. */
  function minuteAt(clientY: number, col: number): number {
    const top = options.columns()[col]?.getBoundingClientRect().top ?? 0
    return Math.min(Math.max((clientY - top) / options.pxPerMin(), 0), DAY_MINUTES - 1)
  }

  /** The column under the pointer, or the nearest one at either end. */
  function columnAt(clientX: number): number {
    const columns = options.columns()
    let index = 0
    columns.forEach((column, i) => {
      if (column && clientX >= column.getBoundingClientRect().left) index = i
    })
    return index
  }

  function columnLeft(col: number): number {
    return options.columns()[col]?.getBoundingClientRect().left ?? 0
  }

  /**
   * Follows the pointer from a press until it is let go. `move` runs once the press has become a
   * drag; `end` after a drag was let go (not after a plain click, nor after Esc).
   */
  function track(down: PointerEvent, move: (event: PointerEvent) => void, end: () => void): void {
    const target = down.currentTarget as HTMLElement | null
    let started = false

    const onMove = (event: PointerEvent): void => {
      if (event.pointerId !== down.pointerId) return
      if (!started) {
        if (Math.hypot(event.clientX - down.clientX, event.clientY - down.clientY) < THRESHOLD) return
        started = true
        // Keeps the moves coming when the pointer leaves the block or the window.
        target?.setPointerCapture?.(down.pointerId)
      }
      event.preventDefault()
      move(event)
    }
    const onUp = (event: PointerEvent): void => {
      if (event.pointerId !== down.pointerId) return
      finish()
      if (!started) return
      swallowClick()
      end()
      state.value = null
    }
    const onKey = (event: KeyboardEvent): void => {
      if (event.key !== 'Escape') return
      event.stopPropagation()
      finish()
      state.value = null
    }
    const finish = (): void => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
      window.removeEventListener('pointercancel', onCancel)
      window.removeEventListener('keydown', onKey, true)
      stop = null
    }
    const onCancel = (event: PointerEvent): void => {
      if (event.pointerId !== down.pointerId) return
      finish()
      state.value = null
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    window.addEventListener('pointercancel', onCancel)
    window.addEventListener('keydown', onKey, true)
    stop = finish
  }

  /** The click the browser sends after a drag is let go would open what lies under the pointer. */
  function swallowClick(): void {
    const swallow = (event: MouseEvent): void => {
      event.stopPropagation()
      event.preventDefault()
    }
    window.addEventListener('click', swallow, { capture: true, once: true })
    setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 0)
  }

  /** A press on empty space of a column: a drag there selects the time of a new event. */
  function fromLane(event: PointerEvent, col: number): void {
    if (!accepts(event) || (event.target as Element).closest('[data-block]')) return
    const anchor = roundSlot(minuteAt(event.clientY, col), STEP)
    track(
      event,
      (move) => {
        const at = roundSlot(minuteAt(move.clientY, col), STEP)
        state.value = { mode: 'create', col, startMin: Math.min(anchor, at), endMin: Math.max(anchor, at) + STEP }
      },
      () => {
        const s = state.value
        if (s?.mode === 'create') options.create(col, s.startMin, s.endMin)
      },
    )
  }

  /**
   * A press on an event block: `move` drags the whole block (across columns in a week), `resize`
   * its end. `span` is the part of the event drawn in this column; it stays inside the day.
   */
  function fromBlock(event: PointerEvent, item: EventItem, span: { startMin: number; endMin: number }, col: number, mode: 'move' | 'resize'): void {
    if (!accepts(event)) return
    track(
      event,
      (move) => {
        const ppm = options.pxPerMin()
        const raw = Math.round((move.clientY - event.clientY) / ppm / STEP) * STEP
        if (mode === 'resize') {
          const minutes = Math.min(Math.max(raw, span.startMin + STEP - span.endMin), DAY_MINUTES - span.endMin)
          state.value = { mode, key: item.key, days: 0, minutes, dx: 0, dy: 0 }
          return
        }
        const minutes = Math.min(Math.max(raw, -span.startMin), DAY_MINUTES - span.endMin)
        const target = columnAt(move.clientX)
        state.value = { mode, key: item.key, days: target - col, minutes, dx: columnLeft(target) - columnLeft(col), dy: minutes * ppm }
      },
      () => {
        const s = state.value
        if (s && s.mode !== 'create' && (s.days || s.minutes)) options.drop({ item, mode: s.mode, days: s.days, minutes: s.minutes })
      },
    )
  }

  onBeforeUnmount(() => stop?.())

  return { state, fromLane, fromBlock }
}
