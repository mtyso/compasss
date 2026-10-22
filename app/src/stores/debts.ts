import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'
import type { DebtMethod } from '@/lib/debts'
import type { Currency } from '@/lib/format'
import { useGoals } from '@/stores/goals'
import { useMoney, type Transaction } from '@/stores/money'
import { usePlan } from '@/stores/plan'

/** A debt not paid off yet (GET /debts): the account below zero and its debt goal, if it has a plan. */
export interface OweRow {
  account_id: number
  name: string
  currency: Currency
  /** What is owed, in the debt's currency, positive. */
  amount: number
  amount_uzs: number
  rate: number | null
  goal: {
    id: number
    monthly: number
    monthly_uzs: number
    deadline: string | null
    paid_off_on: string | null
    interest: number | null
    /** What is still to pay this period. */
    due: number
    due_uzs: number
  } | null
}

/** Money lent and not back yet. */
export interface OwedRow {
  account_id: number
  name: string
  currency: Currency
  amount: number
  amount_uzs: number
  due_on: string | null
  overdue: boolean
  lent_on: string | null
  from_account_id: number | null
}

export interface DebtsOverview {
  owe: OweRow[]
  owed: OwedRow[]
  owe_uzs: number
  owed_uzs: number
  method: DebtMethod
}

export interface LendInput {
  name?: string
  lent_account_id?: number
  account_id: number
  amount: number
  due_on?: string | null
}

export interface BorrowInput {
  name: string
  account_id?: number | null
  currency?: Currency
  amount: number
  monthly_amount?: number | null
  deadline?: string | null
  rate?: number | null
}

export const useDebts = defineStore('debts', () => {
  const overview = ref<DebtsOverview | null>(null)

  async function load(): Promise<void> {
    overview.value = await api<DebtsOverview>('/debts')
  }

  /** Every move is a transfer: balances, the limit and the goals change with it. */
  async function after<T extends DebtsOverview>(result: T): Promise<T> {
    overview.value = result
    await Promise.all([useMoney().changed(), useGoals().loaded ? useGoals().load() : undefined, usePlan().loadToday().catch(() => undefined)])
    return result
  }

  async function lend(input: LendInput): Promise<DebtsOverview & { account_id: number; transaction: Transaction }> {
    return after(await api('/debts/lend', { method: 'POST', body: JSON.stringify(input) }))
  }

  async function borrow(input: BorrowInput): Promise<DebtsOverview & { account_id: number; goal_id: number | null }> {
    return after(await api('/debts/borrow', { method: 'POST', body: JSON.stringify(input) }))
  }

  /** «Вернул» on money lent, «Внести платёж» on a debt; `amount` is in the debt's currency. */
  async function settle(accountId: number, input: { account_id: number; amount?: number; occurred_at?: string }): Promise<DebtsOverview & { transaction: Transaction }> {
    return after(await api(`/debts/${accountId}/settle`, { method: 'POST', body: JSON.stringify(input) }))
  }

  /** The order to pay off in, and `extraUzs` a month more for the first debt in it. */
  async function plan(method: DebtMethod, extraUzs: number): Promise<DebtsOverview & { goal_id: number | null }> {
    return after(await api('/debts/plan', { method: 'POST', body: JSON.stringify({ method, extra_uzs: extraUzs }) }))
  }

  function reset(): void {
    overview.value = null
  }

  return { overview, load, lend, borrow, settle, plan, reset }
})
