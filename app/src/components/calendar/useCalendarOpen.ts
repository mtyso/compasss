import { nextTick, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { showToast } from '@/components/ui/toast'
import { useThingActions } from '@/components/things/useThingActions'
import { api, ApiError } from '@/lib/api'
import { habitAfter, habitOnDay, shiftEvent } from '@/lib/calendar'
import { tapValue } from '@/lib/habits'
import { haptics } from '@/lib/haptics'
import {
  useCalendar,
  type CalendarEvent,
  type CalendarFeed,
  type CalendarItem,
  type EventInput,
  type EventItem,
  type HabitItem,
  type RoutineBlock,
  type RoutineOccurrence,
  type Scope,
  type TaskItem,
} from '@/stores/calendar'
import { useHabits, type Habit } from '@/stores/habits'
import { useQuickAdd, type Transaction } from '@/stores/money'
import { usePlan, type RecurringPayment } from '@/stores/plan'
import { useTasks, type Task } from '@/stores/tasks'
import type { DragDrop } from './useCalendarDrag'

/** A change shown on an item before the feed brings it: a tick, a habit's value, a block moved. */
interface Flip {
  patch: Partial<CalendarItem>
  /** The server has it: the next feed shows the real thing and the flip goes. */
  settled: boolean
}

interface Move {
  item: EventItem
  event: CalendarEvent
  input: EventInput
}

/**
 * What a tap or a drop on the calendar does, with the sheets it opens (mounted once in CalendarPage):
 * an event opens EventSheet, a task TaskSheet, a payment PaySheet (or its settings, or the expense
 * that paid it), a payday, a goal or money lent their screens; a habit logs for its day, a hold asks
 * for the number. A tick, a log or a drop shows on the item at once and stays until the feed that
 * comes after the change arrives.
 */
export function useCalendarOpen(feed: () => CalendarFeed | null, today: () => string) {
  const calendar = useCalendar()
  const tasks = useTasks()
  const habits = useHabits()
  const plan = usePlan()
  const quick = useQuickAdd()
  const router = useRouter()
  const { t } = useI18n()
  const actions = useThingActions()

  const eventSheet = reactive({
    open: false,
    event: null as CalendarEvent | null,
    occurrence: null as { date: string; end_date: string } | null,
    draft: null as EventInput | null,
  })
  const taskSheet = reactive({ open: false, task: null as Task | null })
  const paySheet = reactive({ open: false, payment: null as RecurringPayment | null })
  const valueSheet = reactive({ open: false, habit: null as Habit | null, item: null as HabitItem | null })
  const routineSheet = reactive({ open: false, block: null as RoutineBlock | null })
  const scopeSheet = reactive({ open: false, allowFollowing: true })

  /* ---------- Changes shown before the feed has them ---------- */

  const flips = reactive(new Map<string, Flip>())

  /** The items with the local changes laid over them. */
  function patched(items: CalendarItem[]): CalendarItem[] {
    if (!flips.size) return items
    return items.map((item) => {
      const flip = flips.get(item.key)
      return flip ? ({ ...item, ...flip.patch } as CalendarItem) : item
    })
  }

  watch(
    () => calendar.feed,
    () => {
      for (const [key, flip] of flips) if (flip.settled) flips.delete(key)
    },
  )

  function settle(key: string): void {
    const flip = flips.get(key)
    if (flip) flip.settled = true
  }

  /** Shows `patch` on the item while `action` runs, then reloads the feed that will have it. */
  async function withFlip(item: CalendarItem, patch: Partial<CalendarItem>, action: () => Promise<void>): Promise<void> {
    flips.set(item.key, { patch, settled: false })
    await action()
    settle(item.key)
    void calendar.refresh()
  }

  /* ---------- Opening things ---------- */

  async function openItem(item: CalendarItem): Promise<void> {
    switch (item.type) {
      case 'event':
        return openEvent(item)
      case 'task': {
        // A task done long ago is not in the store: the calendar only shows it.
        const task = tasks.byId.get(item.task_id)
        if (!task) return
        taskSheet.task = task
        taskSheet.open = true
        return
      }
      case 'payment':
        return openPayment(item)
      case 'income':
        void router.push({ name: 'income' })
        return
      case 'goal':
        void router.push({ name: 'goal', params: { id: String(item.goal_id) } })
        return
      case 'lent':
        void router.push({ name: 'debts' })
        return
      case 'habit':
        tapHabit(item)
    }
  }

  async function openEvent(item: EventItem): Promise<void> {
    let event = feed()?.series[item.event_id] ?? null
    if (!event) {
      try {
        event = await calendar.getEvent(item.event_id)
      } catch {
        showToast({ message: t('calendar.loadFailed'), tone: 'danger' })
        return
      }
    }
    eventSheet.event = event
    eventSheet.occurrence = { date: item.date, end_date: item.end_date }
    eventSheet.draft = null
    eventSheet.open = true
  }

  async function openPayment(item: Extract<CalendarItem, { type: 'payment' }>): Promise<void> {
    if (item.paid) {
      if (!item.transaction_id) return
      try {
        quick.start(await api<Transaction>(`/transactions/${item.transaction_id}`))
      } catch {
        showToast({ message: t('calendar.loadFailed'), tone: 'danger' })
      }
      return
    }
    const payment = plan.payments.find((p) => p.id === item.payment_id)
    if (item.projected || !payment) {
      void router.push({ name: 'payments', query: { edit: String(item.payment_id) } })
      return
    }
    paySheet.payment = payment
    paySheet.open = true
  }

  /** «Изменить» in PaySheet: the payment's own settings. */
  function editPayment(payment: RecurringPayment): void {
    void router.push({ name: 'payments', query: { edit: String(payment.id) } })
  }

  function newEvent(draft: EventInput): void {
    haptics.impact('light')
    eventSheet.event = null
    eventSheet.occurrence = null
    eventSheet.draft = draft
    eventSheet.open = true
  }

  async function openRoutine(occurrence: RoutineOccurrence): Promise<void> {
    if (!calendar.routineLoaded) await calendar.loadRoutine().catch(() => {})
    const block = calendar.blocks.find((b) => b.id === occurrence.block_id)
    if (!block) return
    routineSheet.block = block
    routineSheet.open = true
  }

  /* ---------- Ticks and logs ---------- */

  function toggleTask(item: TaskItem): void {
    const task = tasks.byId.get(item.task_id)
    if (item.projected || !task) return
    void withFlip(item, { done: !item.done }, () => actions.toggleTask(task))
  }

  function storeHabit(item: HabitItem): Habit | null {
    return habits.habits.find((h) => h.id === item.habit_id) ?? null
  }

  /** Logs a habit for the item's day, with the value a tap gives on that day. A future day only shows. */
  function tapHabit(item: HabitItem): void {
    const habit = storeHabit(item)
    if (item.state === 'future' || !habit) return
    log(item, habit, tapValue(habitOnDay(habit, item)))
  }

  function log(item: HabitItem, habit: Habit, value: number): void {
    void withFlip(item, habitAfter(item, value, today()), () => actions.logHabit(habit, value, item.date))
  }

  /** A hold on a count or limit habit: the exact number for that day. */
  function holdHabit(item: HabitItem): void {
    const habit = storeHabit(item)
    if (item.state === 'future' || !habit || habit.type === 'check') return
    valueSheet.habit = habitOnDay(habit, item)
    valueSheet.item = item
    valueSheet.open = true
  }

  function saveValue(value: number): void {
    const item = valueSheet.item
    const habit = item && storeHabit(item)
    if (item && habit) log(item, habit, value)
  }

  /* ---------- Drops ---------- */

  let pending: Move | null = null

  /**
   * An event block let go in a new place: it stays there, and the change is saved for this
   * occurrence; a repeating series asks first which occurrences it is for.
   */
  function dropEvent(drop: DragDrop): void {
    const event = feed()?.series[drop.item.event_id]
    if (!event) return
    const { input, patch } = shiftEvent(event, drop.item.date, drop)
    haptics.selection()
    flips.set(drop.item.key, { patch, settled: false })
    // An exception of a series is an event of its own: no question there.
    if (!event.repeat || event.series_id !== null) {
      void saveMove({ item: drop.item, event, input }, 'all')
      return
    }
    pending = { item: drop.item, event, input }
    scopeSheet.allowFollowing = drop.item.date !== event.starts_on
    scopeSheet.open = true
  }

  async function saveMove(move: Move, scope: Scope): Promise<void> {
    try {
      await calendar.saveEvent(move.input, move.event.id, scope, move.item.date)
      settle(move.item.key)
    } catch (e) {
      flips.delete(move.item.key)
      haptics.notify('error')
      const message = e instanceof ApiError && e.status === 422 ? Object.values(e.errors)[0]?.[0] : undefined
      showToast({ message: message ?? t('events.failed'), tone: 'danger' })
    }
  }

  function pickScope(scope: Scope): void {
    const move = pending
    pending = null
    if (move) void saveMove(move, scope)
  }

  // Closing the question without an answer (Esc, the scrim, back) puts the block back. The answer,
  // when there is one, may come right after the sheet closes, so wait for it a moment.
  watch(
    () => scopeSheet.open,
    (open) => {
      if (open) return
      void nextTick(() =>
        setTimeout(() => {
          if (!pending || scopeSheet.open) return
          flips.delete(pending.item.key)
          pending = null
        }, 0),
      )
    },
  )

  return {
    eventSheet,
    taskSheet,
    paySheet,
    valueSheet,
    routineSheet,
    scopeSheet,
    patched,
    openItem,
    editPayment,
    newEvent,
    openRoutine,
    toggleTask,
    tapHabit,
    holdHabit,
    saveValue,
    dropEvent,
    pickScope,
  }
}
