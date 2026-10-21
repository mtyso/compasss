import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { showToast } from '@/components/ui/toast'
import { haptics } from '@/lib/haptics'
import { tapValue } from '@/lib/habits'
import { dueLabel } from '@/lib/tasks'
import { isoDate } from '@/lib/dates'
import { useHabits, type Habit } from '@/stores/habits'
import { useSettings } from '@/stores/settings'
import { useTasks, type Task } from '@/stores/tasks'

/**
 * What a tap on a task checkbox or a habit button does, the same on «Сегодня» and «Дела»: the
 * change, the haptic that fits its outcome, and a toast with «Вернуть» where it can be undone.
 */
export function useThingActions() {
  const tasks = useTasks()
  const habits = useHabits()
  const settings = useSettings()
  const { t } = useI18n()

  async function toggleTask(task: Task): Promise<void> {
    try {
      if (task.done_at) {
        haptics.selection()
        await tasks.reopen(task)
        return
      }
      haptics.impact('light')
      const { task: fresh, previous_due_on: previous } = await tasks.complete(task)
      haptics.notify('success')
      const next = previous && fresh.due_on ? dueLabel({ due_on: fresh.due_on, due_time: null }, isoDate(), t, settings.locale) : null
      showToast({
        message: t('tasks.completed'),
        detail: next ? t('tasks.nextTime', { date: next }) : task.title,
        tone: 'success',
        action: { label: t('tasks.undo'), run: () => void tasks.reopen(fresh, previous) },
      })
    } catch {
      haptics.notify('error')
      showToast({ message: t('tasks.failed'), tone: 'danger' })
    }
  }

  async function logHabit(habit: Habit, value = tapValue(habit)): Promise<void> {
    const before = habit.today.state
    const wasAllDone = allHabitsDone()
    haptics.impact('light')
    try {
      const fresh = await habits.log(habit, value)
      if (fresh.today.state === 'over' && before !== 'over') haptics.notify('warning')
      else if (fresh.type !== 'limit' && fresh.today.state === 'done' && before !== 'done') haptics.notify('success')
      if (!wasAllDone && allHabitsDone()) showToast({ message: t('habits.allDone'), tone: 'success' })
    } catch {
      haptics.notify('error')
      showToast({ message: t('habits.failed'), tone: 'danger' })
    }
  }

  /** Every habit due today is done (limits that are not broken count as done). */
  function allHabitsDone(): boolean {
    const due = habits.forToday
    return due.some((h) => h.type !== 'limit') && due.every((h) => h.today.state === 'done')
  }

  return { toggleTask, logHabit }
}

/** How long a ticked-off row stays in place, checked, before it leaves the list. */
export const TICK_LINGER_MS = 1100

/**
 * A task list where a ticked row does not vanish under the finger: for a moment it stays where it
 * was, checked, and then animates out. A repeating task then shows up again at its next date.
 */
export function useTaskToggle(source: () => Task[]) {
  const tasks = useTasks()
  const { toggleTask } = useThingActions()
  const lingering = reactive(new Map<number, Task>())

  const shown = computed(() => source().map((task) => lingering.get(task.id) ?? task))
  const justDone = computed(() => new Set(lingering.keys()))

  async function toggle(task: Task): Promise<void> {
    // «Вернуть» on the toast undoes it; a second tap here would tick a repeating task twice.
    if (lingering.has(task.id)) return
    if (!task.done_at) {
      lingering.set(task.id, { ...task, done_at: new Date().toISOString() })
      setTimeout(() => lingering.delete(task.id), TICK_LINGER_MS)
    }
    await toggleTask(task)
    const now = tasks.byId.get(task.id)
    // The save failed and the store put the task back: show it unticked at once.
    if (now && !now.done_at && now.due_on === task.due_on && !task.done_at) lingering.delete(task.id)
  }

  return { shown, justDone, toggle }
}
