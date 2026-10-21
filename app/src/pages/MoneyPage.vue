<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { ChevronLeft, ChevronRight, Loader2, Plus } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import TransactionRow from '@/components/money/TransactionRow.vue'
import { api } from '@/lib/api'
import { dateAtNoon, isoDate, isoMonth, shiftMonth } from '@/lib/dates'
import { formatDayHeading, formatMoney, formatMonthTitle, formatNumber } from '@/lib/format'
import { useMoney, useQuickAdd, type Transaction } from '@/stores/money'
import { usePlan, type Budget } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

interface Summary {
  income_uzs: number
  expense_uzs: number
  one_off_expense_uzs: number
  by_category: { type: 'expense' | 'income'; category_id: number; total_uzs: number; count: number }[]
}

const money = useMoney()
const plan = usePlan()
const quick = useQuickAdd()
const settings = useSettings()

const month = ref(isoMonth())
const summary = ref<Summary | null>(null)
const budget = ref<Budget | null>(null)
const transactions = ref<Transaction[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(true)
const loadingMore = ref(false)
const failed = ref(false)

const monthTitle = computed(() => formatMonthTitle(month.value, settings.locale))
const isCurrentMonth = computed(() => month.value === isoMonth())

/**
 * Spending by main category, budgeted ones first. A budgeted bar is measured against its limit,
 * with a marker where it «should be» today; an unbudgeted one against the biggest category.
 */
const spending = computed(() => {
  const spent = new Map((summary.value?.by_category ?? []).filter((r) => r.type === 'expense').map((r) => [r.category_id, r.total_uzs]))
  const pace = budget.value?.pace ?? 0
  const budgeted = (budget.value?.items ?? []).map((item) => {
    const category = money.categoryById.get(item.category_id)
    const ratio = item.limit_uzs > 0 ? item.spent_uzs / item.limit_uzs : 0
    const state = ratio > 1 ? 'over' : ratio > pace + 0.1 && category?.necessity === 'flexible' ? 'fast' : 'ok'
    return { category_id: item.category_id, category, total: item.spent_uzs, limit: item.limit_uzs, forecast: item.forecast_uzs, share: Math.min(1, ratio), state }
  })
  const budgetedIds = new Set(budgeted.map((row) => row.category_id))
  const others = [...spent.entries()].filter(([id]) => !budgetedIds.has(id))
  const max = Math.max(1, ...others.map(([, total]) => total))
  return {
    pace,
    rows: [
      ...budgeted.sort((a, b) => b.share - a.share),
      ...others.map(([id, total]) => ({ category_id: id, category: money.categoryById.get(id), total, limit: null, forecast: null, share: total / max, state: 'none' })),
    ],
  }
})

const days = computed(() => {
  const groups = new Map<string, Transaction[]>()
  for (const transaction of transactions.value) {
    const day = isoDate(new Date(transaction.occurred_at))
    groups.set(day, [...(groups.get(day) ?? []), transaction])
  }
  return [...groups.entries()].map(([day, items]) => ({
    day,
    heading: formatDayHeading(dateAtNoon(day), settings.locale),
    spent: items.filter((t) => t.type === 'expense').reduce((sum, t) => sum + t.amount_uzs, 0),
    items,
  }))
})

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [, monthSummary, page, monthBudget] = await Promise.all([
      money.ensureLoaded(),
      api<Summary>(`/summary?month=${month.value}`),
      api<{ data: Transaction[]; next_cursor: string | null }>(`/transactions?month=${month.value}`),
      plan.loadBudget(month.value),
    ])
    summary.value = monthSummary
    budget.value = monthBudget
    transactions.value = page.data
    nextCursor.value = page.next_cursor
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadMore(): Promise<void> {
  if (!nextCursor.value) return
  loadingMore.value = true
  try {
    const page = await api<{ data: Transaction[]; next_cursor: string | null }>(
      `/transactions?month=${month.value}&cursor=${encodeURIComponent(nextCursor.value)}`,
    )
    transactions.value.push(...page.data)
    nextCursor.value = page.next_cursor
  } finally {
    loadingMore.value = false
  }
}

onMounted(load)
watch([month, () => money.revision], load)
</script>

<template>
  <div class="flex flex-col gap-5">
    <header class="flex items-center justify-between gap-2 px-1">
      <h1 class="font-display text-2xl font-bold">{{ $t('money.title') }}</h1>
      <div class="flex items-center gap-1">
        <button type="button" class="grid size-8 place-items-center rounded-full active:bg-line" :aria-label="$t('money.prevMonth')" @click="month = shiftMonth(month, -1)">
          <ChevronLeft class="size-5" />
        </button>
        <span class="min-w-28 text-center text-sm font-medium">{{ monthTitle }}</span>
        <button
          type="button"
          class="grid size-8 place-items-center rounded-full active:bg-line disabled:opacity-30"
          :aria-label="$t('money.nextMonth')"
          :disabled="isCurrentMonth"
          @click="month = shiftMonth(month, 1)"
        >
          <ChevronRight class="size-5" />
        </button>
      </div>
    </header>

    <div v-if="loading && !summary" class="grid place-items-center py-16"><Loader2 class="size-6 animate-spin text-ink-3" /></div>
    <p v-else-if="failed" class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="summary">
      <!-- Month totals -->
      <article class="flex flex-col gap-4 rounded-3xl bg-surface p-5 shadow-sm">
        <div class="flex flex-col gap-1">
          <span class="text-xs font-semibold text-ink-3">{{ $t('money.spent') }}</span>
          <span class="num font-display text-3xl font-bold">{{ formatMoney(summary.expense_uzs, 'UZS', settings.locale) }}</span>
          <span v-if="summary.one_off_expense_uzs" class="text-xs text-ink-3">
            {{ $t('money.oneOffPart', { amount: formatMoney(summary.one_off_expense_uzs, 'UZS', settings.locale) }) }}
          </span>
        </div>
        <div class="grid grid-cols-2 gap-3 border-t border-line pt-3">
          <div class="flex min-w-0 flex-col">
            <span class="text-xs text-ink-3">{{ $t('money.earned') }}</span>
            <span class="num truncate font-semibold text-money">{{ formatMoney(summary.income_uzs, 'UZS', settings.locale) }}</span>
          </div>
          <div class="flex min-w-0 flex-col">
            <span class="text-xs text-ink-3">{{ $t('money.net') }}</span>
            <span class="num truncate font-semibold" :class="summary.income_uzs - summary.expense_uzs < 0 ? 'text-danger' : ''">
              {{ formatMoney(summary.income_uzs - summary.expense_uzs, 'UZS', settings.locale) }}
            </span>
          </div>
        </div>
      </article>

      <!-- Accounts -->
      <section class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between px-1">
          <h2 class="text-sm font-semibold">{{ $t('money.accounts') }}</h2>
          <RouterLink :to="{ name: 'accounts' }" class="num text-xs text-ink-3">
            {{ $t('money.total') }} {{ formatMoney(money.totalUzs, 'UZS', settings.locale) }}
          </RouterLink>
        </div>
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1">
          <RouterLink
            v-for="account in money.activeAccounts"
            :key="account.id"
            :to="{ name: 'account-edit', params: { id: account.id } }"
            class="flex min-w-36 flex-col gap-1 rounded-2xl bg-surface px-4 py-3 shadow-sm"
          >
            <span class="truncate text-xs text-ink-3">{{ account.name }}</span>
            <span class="num font-semibold" :class="{ 'text-danger': account.balance < 0 }">
              {{ formatMoney(account.balance, account.currency, settings.locale) }}
            </span>
          </RouterLink>
          <RouterLink
            :to="{ name: 'account-new' }"
            class="flex min-w-24 flex-col items-center justify-center gap-1 rounded-2xl border border-dashed border-line px-4 py-3 text-xs text-ink-3"
          >
            <Plus class="size-4" />
            {{ $t('money.addAccount') }}
          </RouterLink>
        </div>
      </section>

      <!-- Spending by category, against the budget -->
      <section class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between px-1">
          <h2 class="text-sm font-semibold">{{ $t('money.byCategory') }}</h2>
          <RouterLink :to="{ name: 'budget' }" class="text-xs text-ink-3">{{ budget?.items.length ? $t('money.editBudget') : $t('money.setBudget') }}</RouterLink>
        </div>
        <p v-if="!spending.rows.length" class="rounded-2xl border border-dashed border-line px-4 py-5 text-sm text-ink-3">{{ $t('money.noSpending') }}</p>
        <div v-else class="flex flex-col gap-3.5 rounded-3xl bg-surface p-4 shadow-sm">
          <div v-for="row in spending.rows" :key="row.category_id" class="flex items-center gap-3">
            <CategoryIcon :icon="row.category?.icon" :color="row.category?.color" size="sm" />
            <div class="flex min-w-0 flex-1 flex-col gap-1">
              <div class="flex items-baseline justify-between gap-2">
                <span class="truncate text-sm">{{ row.category?.names[settings.locale] }}</span>
                <span v-if="row.limit" class="num text-sm whitespace-nowrap">
                  <span class="font-medium" :class="{ 'text-danger': row.state === 'over' }">{{ formatNumber(row.total, 'UZS') }}</span>
                  <span class="text-ink-3"> / {{ formatNumber(row.limit, 'UZS') }}</span>
                </span>
                <span v-else class="num text-sm font-medium whitespace-nowrap">{{ formatMoney(row.total, 'UZS', settings.locale) }}</span>
              </div>
              <div class="relative h-1.5 rounded-full bg-line">
                <div
                  class="bar h-full rounded-full"
                  :class="{ 'bg-danger': row.state === 'over', 'bg-habit': row.state === 'fast', 'bg-money': row.state === 'ok', 'bg-ink-3': row.state === 'none' }"
                  :style="{ width: `${Math.max(2, row.share * 100)}%` }"
                />
                <span
                  v-if="row.limit && spending.pace > 0 && spending.pace < 1"
                  class="absolute -top-1 h-3.5 w-0.5 rounded-full bg-ink"
                  :style="{ left: `calc(${spending.pace * 100}% - 1px)` }"
                  :title="$t('money.paceMarker')"
                />
              </div>
              <span v-if="row.limit && row.forecast && row.forecast > row.limit && row.state !== 'over'" class="text-[11px] text-habit">
                {{ $t('money.forecast', { amount: formatMoney(row.forecast, 'UZS', settings.locale) }) }}
              </span>
            </div>
          </div>
        </div>
      </section>

      <!-- Operations -->
      <section class="flex flex-col gap-2">
        <h2 class="px-1 text-sm font-semibold">{{ $t('money.operations') }}</h2>
        <p v-if="!transactions.length" class="rounded-2xl border border-dashed border-line px-4 py-5 text-sm text-ink-3">
          {{ $t('money.empty') }}
        </p>
        <div v-for="group in days" :key="group.day" class="flex flex-col overflow-hidden rounded-3xl bg-surface shadow-sm">
          <div class="flex items-baseline justify-between px-4 pt-3 pb-1">
            <span class="text-xs font-semibold text-ink-3">{{ group.heading }}</span>
            <span v-if="group.spent" class="num text-xs text-ink-3">−{{ formatMoney(group.spent, 'UZS', settings.locale) }}</span>
          </div>
          <TransactionRow v-for="item in group.items" :key="item.id" :transaction="item" @click="quick.start(item)" />
        </div>
        <button v-if="nextCursor" type="button" class="py-3 text-sm text-ink-2" :disabled="loadingMore" @click="loadMore">
          {{ $t('money.more') }}
        </button>
      </section>
    </template>
  </div>
</template>

<style scoped>
.bar {
  transition: width 400ms cubic-bezier(0.2, 0.8, 0.2, 1);
}
</style>
