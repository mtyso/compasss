import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { formatMonthTitle, formatShort, type Currency } from '@/lib/format'
import { MONTH_DAYS } from '@/lib/goals'
import { readJson, writeJson } from '@/lib/storage'
import { useGoals, type Goal } from '@/stores/goals'
import { useSettings } from '@/stores/settings'

/** Words the calculators share: a span of time, a month in a sentence, a big sum in short. */
export function useCalcText() {
  const { t } = useI18n()
  const settings = useSettings()

  /** «12 дн.» up to two months, then «7 мес.». */
  function span(days: number): string {
    const d = Math.max(0, Math.round(days))
    return d < 60 ? t('calc.days', { n: d }) : t('calc.months', { n: Math.round(d / MONTH_DAYS) })
  }

  return {
    span,
    spanMonths: (months: number) => span(months * MONTH_DAYS),
    month: (day: string) => formatMonthTitle(day.slice(0, 7), settings.locale),
    short: (amount: number, currency: Currency) => formatShort(amount, currency, settings.locale),
  }
}

/**
 * A calculator's inputs, kept on this device between visits (a convenience, so nothing breaks
 * when storage is missing). Fields saved by an older version are taken only if they still exist.
 */
export function persisted<T extends object>(key: string, defaults: T, enabled = true): T {
  const saved = enabled ? readJson<Partial<T>>(key) : null
  const state = reactive({ ...defaults }) as T
  if (saved) {
    for (const field of Object.keys(defaults) as (keyof T)[]) {
      if (saved[field] !== undefined) state[field] = saved[field] as T[keyof T]
    }
  }
  if (enabled) watch(state, (value) => writeJson(key, value), { deep: true })
  return state
}

/**
 * The goal a calculator was opened from (`?goal=ID` on its address), to fill the fields with its
 * numbers. Null when it was opened on its own, or the goal is gone.
 */
export function useGoalFromRoute(onLoad: (goal: Goal) => void) {
  const route = useRoute()
  const goals = useGoals()
  const id = Number(route.query.goal) || null
  const goal = ref<Goal | null>(null)
  if (id) {
    goals.ensureLoaded().then(() => {
      goal.value = goals.goals.find((g) => g.id === id) ?? null
      if (goal.value) onLoad(goal.value)
    }).catch(() => {})
  }
  return { fromGoal: id !== null, goal }
}
