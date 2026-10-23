<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChartColumn, ChevronLeft, ChevronRight, Handshake, Plus, ReceiptText } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import AccountIcon from '@/components/money/AccountIcon.vue'
import TransactionRow from '@/components/money/TransactionRow.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiProgress from '@/components/ui/UiProgress.vue'
import UiRow from '@/components/ui/UiRow.vue'
import { api } from '@/lib/api'
import { dateAtNoon, isoDate, isoMonth, shiftMonth } from '@/lib/dates'
import { formatDayHeading, formatMoney, formatMonthTitle, formatNumber } from '@/lib/format'
import { toUzs } from '@/lib/money'
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
const { t } = useI18n()

const month = ref(isoMonth())
const summary = ref<Summary | null>(null)
const budget = ref<Budget | null>(null)
const transactions = ref<Transaction[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(true)
const loadingMore = ref(false)
const failed = ref(false)
/** The month whose data is on screen; the content cross-fades when it changes. */
const shownMonth = ref<string | null>(null)

const monthTitle = computed(() => formatMonthTitle(month.value, settings.locale))

/** «Я должен 5 000 000 сум» and «Мне должны 500 000 сум», a line each, or what the screen is for. */
const debtsLines = computed(() => {
  const sum = (type: 'debt' | 'lent') =>
    money.activeAccounts
      .filter((a) => a.type === type)
      .reduce((total, a) => total + toUzs(Math.abs(a.balance), a.currency, money.rates[a.currency] ?? (a.currency === 'UZS' ? 1 : 0)), 0)
  const owe = sum('debt')
  const owed = sum('lent')
  if (!owe && !owed) return [t('debts.lineEmpty')]
  return [
    owe ? `${t('debts.iOwe')} ${formatMoney(owe, 'UZS', settings.locale)}` : null,
    owed ? `${t('debts.owedToMe')} ${formatMoney(owed, 'UZS', settings.locale)}` : null,
  ].filter((line): line is string => !!line)
})
const isCurrentMonth = computed(() => month.value === isoMonth())

/** Under the totals, the way to the month's report: «Отложено 14% дохода». */
const reportLine = computed(() => {
  const income = summary.value?.income_uzs ?? 0
  if (!income) return t('report.lineEmpty')
  const rate = Math.round(((income - (summary.value?.expense_uzs ?? 0)) * 100) / income)
  return rate >= 0 ? t('report.line', { rate }) : t('report.lineDeficit')
})

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
    shownMonth.value = month.value
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
  <div class="page page-wide">
    <ScreenHeader :title="$t('money.title')">
      <template #trailing>
        <div class="-mr-2 flex items-center">
          <UiIconButton size="sm" :label="$t('money.prevMonth')" @click="month = shiftMonth(month, -1)">
            <ChevronLeft class="size-5" />
          </UiIconButton>
          <span class="min-w-[6.5rem] text-center text-sm font-semibold">{{ monthTitle }}</span>
          <UiIconButton size="sm" :label="$t('money.nextMonth')" :disabled="isCurrentMonth" @click="month = shiftMonth(month, 1)">
            <ChevronRight class="size-5" />
          </UiIconButton>
        </div>
      </template>
    </ScreenHeader>

    <!-- First load: the shapes of what is coming, not a spinner. -->
    <div v-if="loading && !summary" class="flex flex-col gap-4" aria-busy="true">
      <div class="skeleton h-4 w-24" />
      <div class="skeleton h-11 w-56" />
      <div class="skeleton h-16 rounded-card" />
      <div class="mt-4 flex flex-col gap-5"><div v-for="i in 3" :key="i" class="flex items-center gap-3"><div class="skeleton size-9 rounded-full" /><div class="skeleton h-4 flex-1" /></div></div>
    </div>
    <p v-else-if="failed && !summary" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <Transition v-else-if="summary" name="fade" mode="out-in">
      <div :key="shownMonth ?? ''" class="flex flex-col gap-(--page-gap)" :class="{ 'opacity-60 transition-opacity': loading }">
        <p v-if="failed" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

        <!-- Phone: one column. Wide screen: the month on the left, its operations on the right. -->
        <div class="page-columns">
          <div class="page-col">
            <!-- Month totals -->
            <section class="flex flex-col gap-4">
              <div class="flex flex-col gap-1.5">
                <span class="text-[0.9375rem] font-medium text-ink-2">{{ $t('money.spent') }}</span>
                <MoneyAmount :amount="summary.expense_uzs" animate class="text-amount leading-none font-semibold" />
                <span v-if="summary.one_off_expense_uzs" class="text-caption text-ink-3">
                  {{ $t('money.oneOffPart', { amount: formatMoney(summary.one_off_expense_uzs, 'UZS', settings.locale) }) }}
                </span>
              </div>
              <dl class="grid grid-cols-2 gap-4 rounded-card bg-surface-2 px-4 py-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                  <dt class="text-caption text-ink-3">{{ $t('money.earned') }}</dt>
                  <dd><MoneyAmount :amount="summary.income_uzs" animate class="truncate font-semibold text-money" /></dd>
                </div>
                <div class="flex min-w-0 flex-col gap-0.5">
                  <dt class="text-caption text-ink-3">{{ $t('money.net') }}</dt>
                  <dd>
                    <MoneyAmount
                      :amount="summary.income_uzs - summary.expense_uzs"
                      animate
                      class="truncate font-semibold"
                      :class="{ 'text-danger': summary.income_uzs - summary.expense_uzs < 0 }"
                    />
                  </dd>
                </div>
              </dl>
              <div class="group">
                <UiRow :to="{ name: 'report', params: { month: shownMonth ?? month } }" :title="$t('report.title')" :subtitle="reportLine">
                  <template #leading><span class="grid size-9 place-items-center rounded-full bg-surface-2 text-ink-2"><ChartColumn class="size-[18px]" :stroke-width="2" /></span></template>
                </UiRow>
              </div>
            </section>

            <!-- Spending by category, against the budget -->
            <section class="flex flex-col gap-3">
              <div class="flex items-baseline justify-between gap-3">
                <h2 class="section-title">{{ $t('money.byCategory') }}</h2>
                <RouterLink :to="{ name: 'budget' }" class="section-link">{{ budget?.items.length ? $t('money.editBudget') : $t('money.setBudget') }}</RouterLink>
              </div>
              <UiEmpty v-if="!spending.rows.length" :text="$t('money.noSpending')" />
              <div v-else class="flex flex-col gap-4 pt-1">
                <div v-for="row in spending.rows" :key="row.category_id" class="flex items-center gap-3">
                  <CategoryIcon :icon="row.category?.icon" :color="row.category?.color" size="sm" />
                  <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <div class="flex items-baseline justify-between gap-2">
                      <span class="truncate text-[0.9375rem]">{{ row.category?.names[settings.locale] }}</span>
                      <span v-if="row.limit" class="num text-[0.9375rem] whitespace-nowrap">
                        <span class="font-semibold" :class="{ 'text-danger': row.state === 'over' }">{{ formatNumber(row.total, 'UZS') }}</span>
                        <span class="text-ink-3"> / {{ formatNumber(row.limit, 'UZS') }}</span>
                      </span>
                      <MoneyAmount v-else :amount="row.total" class="text-[0.9375rem] font-semibold" />
                    </div>
                    <UiProgress
                      size="sm"
                      :value="row.limit ? row.total / row.limit : row.share"
                      :marker="row.limit && spending.pace > 0 && spending.pace < 1 ? spending.pace : null"
                      :tone="row.state === 'over' ? 'danger' : row.state === 'fast' ? 'habit' : row.state === 'ok' ? 'money' : 'neutral'"
                      :title="row.limit ? $t('money.paceMarker') : undefined"
                    />
                    <span v-if="row.limit && row.forecast && row.forecast > row.limit && row.state !== 'over'" class="text-caption font-medium text-habit">
                      {{ $t('money.forecast', { amount: formatMoney(row.forecast, 'UZS', settings.locale) }) }}
                    </span>
                  </div>
                </div>
              </div>
            </section>

            <!-- Accounts -->
            <section class="flex flex-col gap-3">
              <div class="flex items-baseline justify-between gap-3">
                <h2 class="section-title">{{ $t('money.accounts') }}</h2>
                <span class="num text-caption text-ink-3">{{ $t('money.total') }} {{ formatMoney(money.totalUzs, 'UZS', settings.locale) }}</span>
              </div>
              <div class="group">
                <UiRow
                  v-for="account in money.activeAccounts"
                  :key="account.id"
                  :to="{ name: 'account-edit', params: { id: account.id } }"
                  :title="account.name"
                  :subtitle="account.is_cushion ? $t('accounts.cushion') : $t(`accounts.types.${account.type}`)"
                  :chevron="false"
                >
                  <template #leading><AccountIcon :type="account.type" :cushion="account.is_cushion" /></template>
                  <template #trailing>
                    <MoneyAmount :amount="account.balance" :currency="account.currency" class="font-semibold" :class="{ 'text-danger': account.balance < 0 }" />
                  </template>
                </UiRow>
                <UiRow :to="{ name: 'account-new' }" :title="$t('accounts.add')" tone="muted" :chevron="false">
                  <template #leading><span class="grid size-9 place-items-center text-ink-3"><Plus class="size-5" :stroke-width="2.2" /></span></template>
                </UiRow>
              </div>
              <div class="group">
                <UiRow :to="{ name: 'debts' }" :title="$t('debts.title')">
                  <template #subtitle><span v-for="line in debtsLines" :key="line" class="block truncate">{{ line }}</span></template>
                  <template #leading><span class="grid size-9 place-items-center rounded-full bg-surface-2 text-ink-2"><Handshake class="size-[18px]" :stroke-width="2" /></span></template>
                </UiRow>
              </div>
            </section>
          </div>

          <!-- Operations, by day -->
          <div class="page-col">
            <section class="flex flex-col gap-3">
              <h2 class="section-title">{{ $t('money.operations') }}</h2>
              <UiEmpty v-if="!transactions.length" :icon="ReceiptText" :text="$t('money.empty')" tone="money" />
              <div v-for="group in days" :key="group.day" class="flex flex-col">
                <div class="flex items-baseline justify-between pt-2 pb-1">
                  <span class="text-caption font-semibold text-ink-2">{{ group.heading }}</span>
                  <MoneyAmount v-if="group.spent" :amount="group.spent" sign="expense" class="text-caption text-ink-3" />
                </div>
                <div class="group">
                  <TransactionRow v-for="item in group.items" :key="item.id" :transaction="item" @click="quick.start(item)" />
                </div>
              </div>
              <UiButton v-if="nextCursor" variant="secondary" block :loading="loadingMore" class="mt-1" @click="loadMore">
                {{ $t('money.more') }}
              </UiButton>
            </section>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
