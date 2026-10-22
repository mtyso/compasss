import { useI18n } from 'vue-i18n'
import { formatDayMonth, formatMoney } from '@/lib/format'
import type { Goal } from '@/stores/goals'
import { useSettings } from '@/stores/settings'

/** The words a goal is described with in lists and on its screen. */
export function useGoalText() {
  const { t } = useI18n()
  const settings = useSettings()

  const money = (amount: number, goal: Pick<Goal, 'currency'>) => formatMoney(amount, goal.currency ?? 'UZS', settings.locale)
  const day = (date: string) => formatDayMonth(date, settings.locale)
  const dayYear = (date: string) => (date.slice(0, 4) === String(new Date().getFullYear()) ? day(date) : `${day(date)} ${date.slice(0, 4)}`)

  /** «Отстаёт на 1 875 000 сум», «По графику», «До 1 марта», «Собрано». */
  function status(goal: Goal): string {
    if (goal.status === 'behind') return t('goals.status.behind', { amount: money(goal.behind, goal) })
    if (goal.status === 'open' && goal.deadline) return t('goals.status.until', { date: dayYear(goal.deadline) })
    return t(`goals.status.${goal.status}`)
  }

  /** «1 251 000 из 15 000 000 сум» for money, «84 из 80 кг» or steps for the rest. */
  function amounts(goal: Goal): string {
    if (goal.type === 'debt') return t('goals.paidOf', { paid: money(goal.saved, goal), total: money(goal.target_amount ?? 0, goal) })
    if (goal.type !== 'other') return t('goals.savedOf', { saved: money(goal.saved, goal), target: money(goal.target_amount ?? 0, goal) })
    if (goal.target_value !== null) return t('goals.valueOf', { value: goal.current_value ?? goal.start_value ?? 0, target: goal.target_value, unit: goal.unit ?? '' }).trim()
    return t('goals.stepsOf', { done: goal.steps.done, total: goal.steps.total })
  }

  /** The date the goal is expected on: by the actual pace when there is one, else by the plan. */
  function forecast(goal: Goal): string | null {
    if (goal.done_at) return t('goals.doneOn', { date: dayYear(goal.done_at.slice(0, 10)) })
    const on = goal.forecast.fact_on ?? goal.forecast.plan_on
    if (goal.status === 'reached' || !on) return null
    return t(goal.forecast.fact_on ? 'goals.factOn' : 'goals.planOn', { date: dayYear(on) })
  }

  return { money, day, dayYear, status, amounts, forecast }
}
