import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

/** A line of the records journal (docs/PLAN.md, D39), in the app's language. */
export interface JournalRecord {
  id: number
  kind: 'streak_milestone' | 'streak_record' | 'goal_first' | 'goal_step' | 'goal_done' | 'debt_closed' | 'cushion' | 'month_budget' | 'savings_rate'
  /** h12 (a habit), g3 (a goal), a7 (a debt), limit, records, contributions or a month (YYYY-MM). */
  subject: string
  value: number | null
  date: string
  text: string
  /** The habit or goal the line is about, to open it. */
  ref: { type: 'habit' | 'goal'; id: number } | null
}

/** «Рекорды»: the journal, newest first. */
export const useRecords = defineStore('records', () => {
  const records = ref<JournalRecord[]>([])
  const loaded = ref(false)

  async function load(): Promise<void> {
    records.value = (await api<{ data: JournalRecord[] }>('/records')).data
    loaded.value = true
  }

  /** Takes a line out for good: the server will not write it again. */
  async function remove(id: number): Promise<void> {
    await api(`/records/${id}`, { method: 'DELETE' })
    records.value = records.value.filter((r) => r.id !== id)
  }

  return { records, loaded, load, remove }
})
