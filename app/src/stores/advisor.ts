import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/lib/api'
import type { Currency } from '@/lib/format'
import { isoMonth } from '@/lib/dates'
import { useAuth } from '@/stores/auth'
import { useGoals, type GoalsPlan } from '@/stores/goals'
import { useMoney } from '@/stores/money'
import { usePlan, type Budget } from '@/stores/plan'

export type Severity = 'alert' | 'warn' | 'good' | 'info'

export interface Insight {
  id: number
  rule: string
  severity: Severity
  status: 'new' | 'seen' | 'useful' | 'dismissed'
  title: string
  body: string
  action: 'mark_one_off' | 'contribute' | null
  created_at: string
}

export type Verdict = 'yes' | 'yes_but' | 'wait' | 'no'

export interface PurchaseVerdict {
  verdict: Verdict
  title: string
  reasons: string[]
  amount_uzs: number
  limit_before_uzs: number
  limit_after_uzs: number
  days_left: number
  wait_until: string | null
}

export type Strictness = 'soft' | 'normal' | 'strict'

export const RULES = [
  'budget_threshold', 'category_pace', 'overspend_forecast', 'large_spend', 'small_things',
  'income_split', 'goal_due', 'goal_leftover', 'goal_pace', 'goal_reached', 'cushion', 'savings_rate', 'good_news', 'forgot_to_record',
] as const

/** The advisor: the «Советы» feed, «Можно ли купить?» and its settings (docs/PLAN.md, section 2). */
export const useAdvisor = defineStore('advisor', () => {
  const auth = useAuth()
  const plan = usePlan()
  const insights = ref<Insight[]>([])
  const newCount = ref(0)
  const loaded = ref(false)
  // This month's budget for the quick-add hint, loaded when the sheet first needs it
  const budget = ref<Budget | null>(null)

  const settings = computed(() => (auth.user?.settings ?? {}) as Record<string, unknown>)
  const strictness = computed(() => (settings.value.advisor_strictness as Strictness | undefined) ?? 'normal')
  const alertPercent = computed(() => Number(settings.value.budget_alert_percent ?? 80))
  const cushionMonths = computed(() => Number(settings.value.cushion_target_months ?? 3))
  const muted = computed(() => (settings.value.muted_rules as string[] | undefined) ?? [])

  async function load(): Promise<void> {
    const result = await api<{ data: Insight[]; new_count: number }>('/insights')
    insights.value = result.data
    newCount.value = result.new_count
    loaded.value = true
  }

  async function markSeen(): Promise<void> {
    if (!newCount.value) return
    await api('/insights/seen', { method: 'POST' })
    newCount.value = 0
    insights.value = insights.value.map((i) => (i.status === 'new' ? { ...i, status: 'seen' } : i))
  }

  function replace(next: Insight): void {
    insights.value = insights.value.map((i) => (i.id === next.id ? next : i))
    if (plan.today) {
      plan.today.insights = plan.today.insights.map((i) => (i.id === next.id ? next : i))
    }
  }

  async function feedback(insight: Insight, value: 'useful' | 'dismissed' | 'mute'): Promise<void> {
    const result = await api<{ insight: Insight; settings: Record<string, unknown> }>(`/insights/${insight.id}/feedback`, {
      method: 'POST',
      body: JSON.stringify({ value }),
    })
    replace(result.insight)
    if (value === 'mute') {
      applySettings(result.settings)
      insights.value = insights.value.map((i) => (i.rule === insight.rule && i.status !== 'useful' ? { ...i, status: 'dismissed' } : i))
    }
    if (plan.today && value !== 'useful') {
      plan.today.insights = plan.today.insights.filter((i) => i.id !== insight.id && !(value === 'mute' && i.rule === insight.rule))
    }
  }

  /** The button on an insight: «Отметить разовой», or «Отложить» into goals (which moves money). */
  async function act(insight: Insight): Promise<void> {
    const result = await api<{ insight: Insight; goals?: GoalsPlan }>(`/insights/${insight.id}/action`, { method: 'POST' })
    replace(result.insight)
    if (result.goals) {
      useGoals().apply(result.goals)
      await useMoney().changed()
    }
  }

  async function canAfford(input: { amount: number; currency: Currency; category_id: number | null }): Promise<PurchaseVerdict> {
    return api<PurchaseVerdict>('/advisor/can-afford', { method: 'POST', body: JSON.stringify(input) })
  }

  async function saveSettings(changes: Record<string, unknown>): Promise<void> {
    const user = await api<{ settings: Record<string, unknown> }>('/me', { method: 'PATCH', body: JSON.stringify({ settings: changes }) })
    applySettings(user.settings)
  }

  function applySettings(next: Record<string, unknown>): void {
    if (auth.user) auth.setUser({ ...auth.user, settings: next })
  }

  /** Budget of the current month, cached for the quick-add hint; `force` after an operation is saved. */
  async function ensureBudget(force = false): Promise<Budget | null> {
    const month = isoMonth()
    if (force || budget.value?.month !== month) {
      try {
        budget.value = await api<Budget>(`/budgets?month=${month}`)
      } catch {
        budget.value = null
      }
    }
    return budget.value
  }

  function reset(): void {
    insights.value = []
    newCount.value = 0
    loaded.value = false
    budget.value = null
  }

  return {
    insights, newCount, loaded, budget, strictness, alertPercent, cushionMonths, muted,
    load, markSeen, feedback, act, canAfford, saveSettings, ensureBudget, reset,
  }
})
