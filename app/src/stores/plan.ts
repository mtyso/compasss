import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'
import type { Currency } from '@/lib/format'
import type { Insight } from '@/stores/advisor'
import type { TodayPlan } from '@/stores/calendar'
import type { GoalsPlan } from '@/stores/goals'
import type { JournalRecord } from '@/stores/records'
import { useMoney, type Transaction } from '@/stores/money'

export interface DailyLimit {
  limit_uzs: number
  spent_today_uzs: number
  left_today_uzs: number
  free_uzs: number
  spendable_uzs: number
  upcoming_payments_uzs: number
  min_balance_uzs: number
  planned_savings_uzs: number
  days_left: number
  until: string
  estimated_end: boolean
}

export interface IncomeSchedule {
  id: number
  name: string
  day_of_month: number
  expected_amount: number
  currency: Currency
  account_id: number | null
  active: boolean
  next_date: string
}

export interface RecurringPayment {
  id: number
  name: string
  amount: number
  currency: Currency
  category_id: number
  account_id: number | null
  day_of_month: number
  next_due_on: string
  overdue: boolean
  active: boolean
}

/** Spending that repeats every month and is not a recurring payment yet (GET /recurring-payments/suggestions). */
export interface PaymentSuggestion {
  key: string
  name: string
  amount: number
  currency: Currency
  category_id: number
  account_id: number
  day_of_month: number
  count: number
  last_on: string
  next_due_on: string
}

/** A money streak (docs/PLAN.md, D38), counted like a habit's: spare days for the daily ones, none for periods. */
export interface MoneyStreak {
  current: number
  best: number
  previous_best: number
  unit: 'day' | 'period'
  spare: number
  next: number | null
}

export interface MoneyStreaks {
  /** Days within the daily limit; `covered`: today is over and a spare day will take it. Null without accounts. */
  limit: (MoneyStreak & { today: 'within' | 'over'; covered: boolean }) | null
  /** Days with an operation or «Трат не было». */
  records: MoneyStreak & { today: 'kept' | 'open'; no_spending: boolean }
  /** Pay periods with every goal's contribution made; `today` is null when the period asks for nothing. */
  contributions: ContributionStreak
}

export type ContributionStreak = MoneyStreak & { today: 'kept' | 'open' | null }

export interface Today {
  date: string
  limit: DailyLimit
  streaks: MoneyStreaks
  has_accounts: boolean
  next_income: IncomeSchedule | null
  payments: RecurringPayment[]
  insights: Insight[]
  insights_new: number
  goals: GoalsPlan
  /** «План дня»: today's routine (with the night reaching in from yesterday) and today's events. */
  plan: TodayPlan
  /** How many routine blocks there are: none means «Задать распорядок дня». */
  routine_blocks: number
  /** On Monday, the week that just ended (D39); null on other days. */
  week: WeekSummary | null
}

/** One week's plain numbers for «Итог недели»: each says what it is about, there is no score made of them. */
export interface WeekNumbers {
  /** Days within the daily limit, of the days the limit was written down. */
  limit: { kept: number; days: number } | null
  /** Times done of times asked, over all habits. */
  habits: { done: number; asked: number } | null
  /** Put into goals (not debts), in som. */
  goals_uzs: number
  /** The main goal's part of it, in days of its contributions. */
  main: { id: number; title: string; days: number } | null
}

export interface WeekSummary extends WeekNumbers {
  from: string
  to: string
  prev: WeekNumbers
  records: JournalRecord[]
}

export interface BudgetItem {
  category_id: number
  limit_uzs: number
  spent_uzs: number
  one_off_uzs: number
  forecast_uzs: number | null
}

export interface Budget {
  month: string
  pace: number
  items: BudgetItem[]
  limit_uzs: number
  spent_uzs: number
  unbudgeted_uzs: number
}

export const usePlan = defineStore('plan', () => {
  const today = ref<Today | null>(null)
  const incomes = ref<IncomeSchedule[]>([])
  const payments = ref<RecurringPayment[]>([])
  const suggestions = ref<PaymentSuggestion[]>([])

  async function loadToday(): Promise<void> {
    today.value = await api<Today>('/today')
  }

  /** «Трат не было» for a day (today or a week back), or taking it back. */
  async function markNoSpending(date: string, value: boolean): Promise<void> {
    const result = await api<{ records: MoneyStreaks['records'] }>(`/days/${date}/no-spending`, { method: 'PUT', body: JSON.stringify({ value }) })
    if (today.value) today.value = { ...today.value, streaks: { ...today.value.streaks, records: result.records } }
  }

  async function loadBudget(month: string): Promise<Budget> {
    return api<Budget>(`/budgets?month=${month}`)
  }

  async function saveBudget(month: string, items: { category_id: number; limit_uzs: number }[]): Promise<Budget> {
    return api<Budget>(`/budgets/${month}`, { method: 'PUT', body: JSON.stringify({ items }) })
  }

  async function loadIncomes(): Promise<void> {
    incomes.value = await api<IncomeSchedule[]>('/income-schedules')
  }

  async function saveIncome(input: Partial<IncomeSchedule>, id?: number): Promise<void> {
    await api(id ? `/income-schedules/${id}` : '/income-schedules', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(input) })
    await loadIncomes()
  }

  async function deleteIncome(id: number): Promise<void> {
    await api(`/income-schedules/${id}`, { method: 'DELETE' })
    await loadIncomes()
  }

  async function loadPayments(): Promise<void> {
    payments.value = await api<RecurringPayment[]>('/recurring-payments')
  }

  async function savePayment(input: Partial<RecurringPayment>, id?: number): Promise<void> {
    await api(id ? `/recurring-payments/${id}` : '/recurring-payments', { method: id ? 'PATCH' : 'POST', body: JSON.stringify(input) })
    await loadPayments()
  }

  async function deletePayment(id: number): Promise<void> {
    await api(`/recurring-payments/${id}`, { method: 'DELETE' })
    await loadPayments()
  }

  /** «Оплачено»: records the expense on the server and refreshes everything that depends on it. */
  async function pay(
    payment: RecurringPayment,
    input: { account_id?: number; amount?: number; occurred_at?: string } = {},
  ): Promise<{ payment: RecurringPayment; transaction: Transaction }> {
    const result = await api<{ payment: RecurringPayment; transaction: Transaction }>(`/recurring-payments/${payment.id}/pay`, {
      method: 'POST',
      body: JSON.stringify(input),
    })
    replacePayment(result.payment)
    await useMoney().loadAccounts()
    useMoney().revision++
    return result
  }

  /** «Пропустить в этот раз»: the next due date without an expense. */
  async function skip(payment: RecurringPayment): Promise<RecurringPayment> {
    const next = await api<RecurringPayment>(`/recurring-payments/${payment.id}/skip`, { method: 'POST' })
    replacePayment(next)
    useMoney().revision++
    return next
  }

  function replacePayment(next: RecurringPayment): void {
    const index = payments.value.findIndex((p) => p.id === next.id)
    if (index >= 0) payments.value[index] = next
  }

  async function loadSuggestions(): Promise<void> {
    suggestions.value = await api<PaymentSuggestion[]>('/recurring-payments/suggestions')
  }

  /** «Не то»: the suggestion does not come back. */
  async function dismissSuggestion(key: string): Promise<void> {
    suggestions.value = suggestions.value.filter((s) => s.key !== key)
    await api('/recurring-payments/suggestions/dismiss', { method: 'POST', body: JSON.stringify({ key }) })
  }

  function reset(): void {
    today.value = null
    incomes.value = []
    payments.value = []
    suggestions.value = []
  }

  return {
    today,
    incomes,
    payments,
    suggestions,
    loadToday,
    markNoSpending,
    loadBudget,
    saveBudget,
    loadIncomes,
    saveIncome,
    deleteIncome,
    loadPayments,
    savePayment,
    deletePayment,
    pay,
    skip,
    loadSuggestions,
    dismissSuggestion,
    reset,
  }
})
