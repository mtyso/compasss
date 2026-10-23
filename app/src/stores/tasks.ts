import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/lib/api'

export type Priority = 0 | 1 | 2
export type RepeatUnit = 'day' | 'week' | 'month' | 'year'

export interface RepeatRule {
  unit: RepeatUnit
  every: number
  /** ISO weekdays for a weekly rule (1 = Monday). */
  days?: number[]
  /** Day of month a monthly or yearly rule keeps to. */
  day?: number
}

/** A repeat rule as the app sends it; the server adds `day` to monthly and yearly rules itself. */
export type RepeatInput = Pick<RepeatRule, 'unit' | 'every' | 'days'>

export interface Task {
  id: number
  title: string
  notes: string | null
  due_on: string | null
  due_time: string | null
  priority: Priority
  repeat: RepeatRule | null
  done_at: string | null
  overdue: boolean
  /** The goal this task is a step towards (docs/PLAN.md, 2.6). */
  goal_id: number | null
}

export type TaskInput = Partial<Pick<Task, 'title' | 'notes' | 'due_on' | 'due_time' | 'priority' | 'goal_id'>> & {
  repeat?: RepeatInput | null
}

/** Tasks (docs/PLAN.md, D21): open ones plus those finished in the last days, filtered on the client. */
export const useTasks = defineStore('tasks', () => {
  const tasks = ref<Task[]>([])
  const done = ref<Task[]>([])
  const loaded = ref(false)

  const byId = computed(() => new Map(tasks.value.map((t) => [t.id, t])))

  async function load(): Promise<void> {
    tasks.value = await api<Task[]>('/tasks')
    loaded.value = true
  }

  async function ensureLoaded(): Promise<void> {
    if (!loaded.value) await load()
  }

  async function loadDone(): Promise<void> {
    done.value = await api<Task[]>('/tasks?done=1')
  }

  function put(task: Task): void {
    const index = tasks.value.findIndex((t) => t.id === task.id)
    if (index >= 0) tasks.value.splice(index, 1, task)
    else tasks.value.push(task)
  }

  async function save(input: TaskInput, id?: number): Promise<Task> {
    const task = await api<Task>(id ? `/tasks/${id}` : '/tasks', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(input) })
    put(task)
    return task
  }

  /** Ticks a task off. The row flips at once; a repeating task then moves to its next date. */
  async function complete(task: Task): Promise<{ task: Task; previous_due_on: string | null }> {
    put({ ...task, done_at: new Date().toISOString() })
    try {
      const result = await api<{ task: Task; previous_due_on: string | null }>(`/tasks/${task.id}/complete`, { method: 'POST' })
      put(result.task)
      return result
    } catch (e) {
      put(task)
      throw e
    }
  }

  async function reopen(task: Task, dueOn?: string | null): Promise<Task> {
    put({ ...task, done_at: null, ...(dueOn ? { due_on: dueOn } : {}) })
    const fresh = await api<Task>(`/tasks/${task.id}/complete`, { method: 'DELETE', body: JSON.stringify(dueOn ? { due_on: dueOn } : {}) })
    put(fresh)
    done.value = done.value.filter((t) => t.id !== task.id)
    return fresh
  }

  async function remove(id: number): Promise<void> {
    await api(`/tasks/${id}`, { method: 'DELETE' })
    tasks.value = tasks.value.filter((t) => t.id !== id)
    done.value = done.value.filter((t) => t.id !== id)
  }

  async function restore(id: number): Promise<void> {
    put(await api<Task>(`/tasks/${id}/restore`, { method: 'POST' }))
  }

  function reset(): void {
    tasks.value = []
    done.value = []
    loaded.value = false
  }

  return { tasks, done, loaded, byId, load, ensureLoaded, loadDone, save, complete, reopen, remove, restore, reset }
})
