import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/lib/api'
import type { Currency } from '@/lib/format'
import { useAuth } from '@/stores/auth'
import type { Habit } from '@/stores/habits'
import { useMoney, type Transaction } from '@/stores/money'
import { usePlan, type ContributionStreak } from '@/stores/plan'
import type { Task } from '@/stores/tasks'

export type GoalType = 'save' | 'buy' | 'debt' | 'other'
export type GoalStatus = 'reached' | 'done' | 'late' | 'behind' | 'on_track' | 'ahead' | 'later' | 'open' | 'no_plan'
/** «Сначала цели»: contributions come out of the money before the daily limit; «из остатка»: after (D25). */
export type GoalMode = 'first' | 'leftover'

export interface InstallmentOption {
  down_percent: number
  down_amount: number
  loan: number
  payment: number | null
  payment_uzs: number | null
  overpay: number | null
}

export interface Installment extends InstallmentOption {
  price: number
  months: number | null
  rate: number | null
  options: InstallmentOption[]
}

export interface Goal {
  id: number
  type: GoalType
  title: string
  icon: string | null
  currency: Currency | null
  target_amount: number | null
  account_id: number | null
  deadline: string | null
  monthly_amount: number | null
  rate: number | null
  price: number | null
  down_percent: number | null
  loan_months: number | null
  installment: Installment | null
  unit: string | null
  start_value: number | null
  target_value: number | null
  current_value: number | null
  sort: number
  started_on: string
  done_at: string | null
  archived: boolean
  saved: number
  remaining: number
  progress: number
  monthly: number
  monthly_required: number
  monthly_uzs: number
  fx_rate: string | null
  period: { planned: number; made: number; due: number; due_uzs: number }
  forecast: { plan_on: string | null; fact_on: string | null; fact_monthly: number | null }
  status: GoalStatus
  behind: number
  steps: { done: number; total: number }
}

export interface GoalsPlan {
  mode: GoalMode
  period: { start: string; end: string; by_income: boolean }
  /** Pay periods in a row with every goal's contribution made (docs/PLAN.md, D38). */
  streak: ContributionStreak
  due_uzs: number
  monthly_uzs: number
  main_id: number | null
  goals: Goal[]
}

export type LeverKey = 'category_cut' | 'leftover' | 'habit' | 'debt_extra'

export interface Lever {
  key: LeverKey
  /** More a month, in the goal's currency. */
  extra: number
  extra_uzs: number
  category_id?: number
  typical_uzs?: number
  percent?: number
  habit_id?: number
  name?: string
  per_week?: number
  saves_uzs?: number
  interest_saved?: number | null
}

export interface GoalDetail {
  goal: Goal
  is_main: boolean
  steps: Task[]
  habits: Habit[]
  history: { month: string; amount: number }[]
  checkins: { date: string; value: number }[]
  speed: {
    monthly_income_uzs: number
    monthly_payments_uzs: number
    other_goals_uzs: number
    essential_uzs: number
    levers: Lever[]
  } | null
  /** This goal's contributions made in full, period after period; null for a goal without money. */
  streak: ContributionStreak | null
}

export type GoalInput = Partial<
  Pick<
    Goal,
    | 'type' | 'title' | 'icon' | 'currency' | 'target_amount' | 'account_id' | 'deadline' | 'monthly_amount' | 'rate'
    | 'price' | 'down_percent' | 'loan_months' | 'unit' | 'start_value' | 'target_value'
  >
> & { cushion?: boolean; saved?: number | null; archived?: boolean; done?: boolean }

/** Goals (docs/PLAN.md, 2.6): the list with this period's plan, one goal in full, «Отложить» and «Купил». */
export const useGoals = defineStore('goals', () => {
  const plan = ref<GoalsPlan | null>(null)
  const loaded = ref(false)

  const goals = computed(() => plan.value?.goals ?? [])
  const active = computed(() => goals.value.filter((g) => !g.done_at))
  const finished = computed(() => goals.value.filter((g) => g.done_at))
  const main = computed(() => goals.value.find((g) => g.id === plan.value?.main_id) ?? null)
  /** Goals with a contribution still to make this period. */
  const due = computed(() => active.value.filter((g) => g.period.due > 0))
  const mode = computed<GoalMode>(() => plan.value?.mode ?? ((useAuth().user?.settings?.goal_mode as GoalMode | undefined) ?? 'first'))

  function apply(next: GoalsPlan): void {
    plan.value = next
    loaded.value = true
    // «Сегодня» shows the same plan; keep it in step without another request.
    const today = usePlan().today
    if (today) today.goals = next
  }

  async function load(): Promise<void> {
    apply(await api<GoalsPlan>('/goals'))
  }

  async function ensureLoaded(): Promise<void> {
    if (!loaded.value) await load()
  }

  async function loadOne(id: number): Promise<GoalDetail> {
    return api<GoalDetail>(`/goals/${id}`)
  }

  /** Saves a goal; money goals change what is free to spend, so the limit is refreshed too. */
  async function save(input: GoalInput, id?: number): Promise<Goal> {
    const goal = await api<Goal>(id ? `/goals/${id}` : '/goals', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(input) })
    await afterChange()
    return goal
  }

  async function remove(id: number): Promise<void> {
    await api(`/goals/${id}`, { method: 'DELETE' })
    await afterChange()
  }

  async function order(ids: number[]): Promise<void> {
    if (plan.value) {
      const byId = new Map(plan.value.goals.map((g) => [g.id, g]))
      plan.value.goals = [...ids.map((id) => byId.get(id)!).filter(Boolean), ...plan.value.goals.filter((g) => !ids.includes(g.id))]
    }
    await api('/goals/order', { method: 'PUT', body: JSON.stringify({ ids }) })
    await load()
  }

  /** «Отложить»: amounts in each goal's currency, all from one account. */
  async function contribute(accountId: number, items: { goal_id: number; amount: number }[]): Promise<Transaction[]> {
    const result = await api<{ transactions: Transaction[]; goals: GoalsPlan }>('/goals/contribute', {
      method: 'POST',
      body: JSON.stringify({ account_id: accountId, items }),
    })
    apply(result.goals)
    await useMoney().changed()
    return result.transactions
  }

  /** «Купил»: the purchase from the goal's jar; on installment the rest becomes a debt goal. */
  async function buy(id: number, input: { category_id: number; amount?: number; installment?: boolean; price?: number; loan_months?: number; rate?: number | null }): Promise<{ goal: Goal; debt: Goal | null }> {
    const result = await api<{ goal: Goal; debt: Goal | null }>(`/goals/${id}/buy`, { method: 'POST', body: JSON.stringify(input) })
    await afterChange()
    await useMoney().changed()
    return result
  }

  async function checkin(id: number, date: string, value: number | null): Promise<Goal> {
    return api<Goal>(`/goals/${id}/checkins/${date}`, { method: 'PUT', body: JSON.stringify({ value }) })
  }

  async function setMode(next: GoalMode): Promise<void> {
    const auth = useAuth()
    const user = await api<{ settings: Record<string, unknown> }>('/me', { method: 'PATCH', body: JSON.stringify({ settings: { goal_mode: next } }) })
    if (auth.user) auth.setUser({ ...auth.user, settings: user.settings })
    await afterChange()
  }

  async function afterChange(): Promise<void> {
    await Promise.all([load(), usePlan().loadToday().catch(() => undefined)])
  }

  function reset(): void {
    plan.value = null
    loaded.value = false
  }

  return { plan, loaded, goals, active, finished, main, due, mode, load, ensureLoaded, loadOne, save, remove, order, contribute, buy, checkin, setMode, apply, reset }
})
