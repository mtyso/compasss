import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'
import type { Currency } from '@/lib/format'
import type { Insight } from '@/stores/advisor'
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

export interface Today {
  date: string
  limit: DailyLimit
  has_accounts: boolean
  next_income: IncomeSchedule | null
  payments: RecurringPayment[]
  insights: Insight[]
  insights_new: number
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

  async function loadToday(): Promise<void> {
    today.value = await api<Today>('/today')
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
  async function pay(payment: RecurringPayment, input: { account_id?: number; amount?: number } = {}): Promise<Transaction> {
    const result = await api<{ payment: RecurringPayment; transaction: Transaction }>(`/recurring-payments/${payment.id}/pay`, {
      method: 'POST',
      body: JSON.stringify(input),
    })
    const index = payments.value.findIndex((p) => p.id === payment.id)
    if (index >= 0) payments.value[index] = result.payment
    await useMoney().loadAccounts()
    useMoney().revision++
    return result.transaction
  }

  function reset(): void {
    today.value = null
    incomes.value = []
    payments.value = []
  }

  return {
    today,
    incomes,
    payments,
    loadToday,
    loadBudget,
    saveBudget,
    loadIncomes,
    saveIncome,
    deleteIncome,
    loadPayments,
    savePayment,
    deletePayment,
    pay,
    reset,
  }
})
