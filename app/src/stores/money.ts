import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/lib/api'
import type { Currency, Locale } from '@/lib/format'

export type AccountType = 'card' | 'cash' | 'savings' | 'deposit' | 'debt' | 'other'
export type TransactionType = 'expense' | 'income' | 'transfer'
export type CategoryKind = 'expense' | 'income'
export type Necessity = 'essential' | 'flexible'

export interface Account {
  id: number
  name: string
  type: AccountType
  currency: Currency
  opening_balance: number
  balance: number
  spendable: boolean
  is_cushion: boolean
  sort: number
  archived: boolean
}

export interface Category {
  id: number
  parent_id: number | null
  kind: CategoryKind
  system_key: string | null
  names: Record<Locale, string>
  icon: string | null
  color: string | null
  necessity: Necessity | null
  sort: number
  archived: boolean
}

export interface Transaction {
  id: number
  type: TransactionType
  account_id: number
  to_account_id: number | null
  amount: number
  currency: Currency
  to_amount: number | null
  rate: string
  amount_uzs: number
  category_id: number | null
  note: string | null
  occurred_at: string
  is_one_off: boolean
  source: 'app' | 'web' | 'bot'
  /** The recurring payment it paid, set by «Оплачено» or when the server matched it to one. */
  recurring_payment_id: number | null
}

export interface TransactionInput {
  type: TransactionType
  account_id: number
  to_account_id?: number
  amount: number
  to_amount?: number
  category_id?: number
  note?: string
  occurred_at: string
  is_one_off?: boolean
  rate?: string
}

export const useMoney = defineStore('money', () => {
  const accounts = ref<Account[]>([])
  const totalUzs = ref(0)
  const spendableUzs = ref(0)
  const categories = ref<Category[]>([])
  /** Today's CBU rates, som per unit: only for the «≈ … сум» hints, the server converts for real. */
  const rates = ref<Partial<Record<Currency, string>>>({})
  /** Bumped after every change to operations, so lists and totals on screen reload. */
  const revision = ref(0)

  const activeAccounts = computed(() => accounts.value.filter((a) => !a.archived))
  const categoryById = computed(() => new Map(categories.value.map((c) => [c.id, c])))
  const accountById = computed(() => new Map(accounts.value.map((a) => [a.id, a])))

  function topCategories(kind: CategoryKind): Category[] {
    return categories.value.filter((c) => c.kind === kind && c.parent_id === null && !c.archived)
  }

  function childCategories(parentId: number): Category[] {
    return categories.value.filter((c) => c.parent_id === parentId && !c.archived)
  }

  /** The main category of a subcategory, or the category itself. */
  function rootOf(category: Category): Category {
    return category.parent_id ? (categoryById.value.get(category.parent_id) ?? category) : category
  }

  async function loadAccounts(): Promise<void> {
    const result = await api<{ accounts: Account[]; total_uzs: number; spendable_uzs: number }>('/accounts')
    accounts.value = result.accounts
    totalUzs.value = result.total_uzs
    spendableUzs.value = result.spendable_uzs
  }

  async function loadCategories(): Promise<void> {
    categories.value = await api<Category[]>('/categories')
  }

  async function loadRates(): Promise<void> {
    const result = await api<{ rates: Partial<Record<Currency, string | null>> }>('/rates')
    rates.value = Object.fromEntries(Object.entries(result.rates).filter(([, rate]) => rate !== null)) as Record<Currency, string>
  }

  async function ensureLoaded(): Promise<void> {
    await Promise.all([
      accounts.value.length ? null : loadAccounts(),
      categories.value.length ? null : loadCategories(),
      Object.keys(rates.value).length ? null : loadRates().catch(() => undefined),
    ])
  }

  async function saveTransaction(input: TransactionInput, id?: number): Promise<Transaction> {
    const saved = await api<Transaction>(id ? `/transactions/${id}` : '/transactions', {
      method: id ? 'PUT' : 'POST',
      body: JSON.stringify({ ...input, source: 'app' }),
    })
    await changed()
    return saved
  }

  async function deleteTransaction(id: number): Promise<void> {
    await api(`/transactions/${id}`, { method: 'DELETE' })
    await changed()
  }

  async function restoreTransaction(id: number): Promise<void> {
    await api(`/transactions/${id}/restore`, { method: 'POST' })
    await changed()
  }

  async function changed(): Promise<void> {
    revision.value++
    await loadAccounts()
  }

  async function saveAccount(input: Partial<Account>, id?: number): Promise<Account> {
    const saved = await api<Account>(id ? `/accounts/${id}` : '/accounts', {
      method: id ? 'PATCH' : 'POST',
      body: JSON.stringify(input),
    })
    await loadAccounts()
    return saved
  }

  async function deleteAccount(id: number): Promise<void> {
    await api(`/accounts/${id}`, { method: 'DELETE' })
    await loadAccounts()
  }

  async function saveCategory(input: Record<string, unknown>, id?: number): Promise<Category> {
    const saved = await api<Category>(id ? `/categories/${id}` : '/categories', {
      method: id ? 'PATCH' : 'POST',
      body: JSON.stringify(input),
    })
    const index = categories.value.findIndex((c) => c.id === saved.id)
    if (index >= 0) categories.value[index] = saved
    else categories.value.push(saved)
    return saved
  }

  function reset(): void {
    accounts.value = []
    categories.value = []
    totalUzs.value = 0
    spendableUzs.value = 0
  }

  return {
    accounts,
    activeAccounts,
    totalUzs,
    spendableUzs,
    categories,
    rates,
    revision,
    categoryById,
    accountById,
    topCategories,
    childCategories,
    rootOf,
    loadAccounts,
    loadCategories,
    ensureLoaded,
    saveTransaction,
    deleteTransaction,
    restoreTransaction,
    saveAccount,
    deleteAccount,
    saveCategory,
    changed,
    reset,
  }
})

/** Opens the quick-add sheet from anywhere: the «+» button, or a tap on an operation to edit it. */
export const useQuickAdd = defineStore('quickAdd', () => {
  const open = ref(false)
  const editing = ref<Transaction | null>(null)
  /** The operation just deleted from the sheet, for the «Вернуть» toast. */
  const deletedId = ref<number | null>(null)

  function start(transaction: Transaction | null = null): void {
    editing.value = transaction
    open.value = true
  }

  function close(): void {
    open.value = false
  }

  return { open, editing, deletedId, start, close }
})
