<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDown, ChartColumn, ChevronLeft, ChevronRight, Handshake, Plus, ReceiptText, X } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import CategoryDonut from '@/components/money/CategoryDonut.vue'
import CategoryStrip from '@/components/money/CategoryStrip.vue'
import AccountIcon from '@/components/money/AccountIcon.vue'
import DayBars from '@/components/money/DayBars.vue'
import PaceLine from '@/components/money/PaceLine.vue'
import ScrubBars, { type ScrubBar } from '@/components/money/ScrubBars.vue'
import TransactionRow from '@/components/money/TransactionRow.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiProgress from '@/components/ui/UiProgress.vue'
import UiRow from '@/components/ui/UiRow.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { monthStats, peakHours, peakWeekday, subcategoriesOf, weekdayAverages, type AnalyticsMonth, type MonthAnalytics } from '@/lib/analytics'
import { api } from '@/lib/api'
import { dateAtNoon, isoDate, isoMonth, shiftMonth } from '@/lib/dates'
import { formatDayHeading, formatDayMonth, formatMoney, formatMonthShort, formatMonthTitle, formatNumber } from '@/lib/format'
import { prefersReducedMotion } from '@/lib/motion'
import { readJson, writeJson } from '@/lib/storage'
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

const CHART_KEY = 'kompas.moneyChart'
type ChartMode = 'days' | 'pace' | 'months'

const month = ref(isoMonth())
const summary = ref<Summary | null>(null)
const budget = ref<Budget | null>(null)
/** The charts; null when the server does not give them (an older one), and the screen goes without. */
const analytics = ref<MonthAnalytics | null>(null)
const transactions = ref<Transaction[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(true)
const loadingMore = ref(false)
const loadingOperations = ref(false)
const failed = ref(false)

/** What the operations list is narrowed to: a day picked on the chart, a category opened in the list. */
const filter = reactive<{ day: string | null; category: number | null }>({ day: null, category: null })
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

/* ── Charts (docs/PLAN.md, D41) ───────────────────────────────────────────── */

const chartMode = ref<ChartMode>(readJson<ChartMode>(CHART_KEY) ?? 'days')
watch(chartMode, (mode) => writeJson(CHART_KEY, mode))
const chartModes = computed(() => (['days', 'pace', 'months'] as const).map((value) => ({ value, label: t(`analytics.modes.${value}`) })))
const uzs = (amount: number) => formatMoney(amount, 'UZS', settings.locale)

const stats = computed(() => (analytics.value ? monthStats(analytics.value) : null))
/** Any month on the chart had spending: otherwise there is nothing to draw yet. */
const hasCharts = computed(() => !!analytics.value?.months.some((m) => m.expense_uzs > 0))

function monthNote(m: AnalyticsMonth): string {
  return [m.income_uzs ? t('analytics.income', { amount: uzs(m.income_uzs) }) : null, m.one_off_uzs ? t('analytics.oneOff', { amount: uzs(m.one_off_uzs) }) : null]
    .filter(Boolean)
    .join(' · ')
}

/** Six months up to the one on screen; those before the app are dots. A click opens the month. */
const monthBars = computed<ScrubBar[]>(() => {
  const data = analytics.value
  if (!data) return []
  const byMonth = new Map(data.months.map((m) => [m.month, m]))
  return Array.from({ length: 6 }, (_, i) => shiftMonth(data.month, i - 5)).map((key) => {
    const m = byMonth.get(key)
    const base = { key, label: formatMonthTitle(key, settings.locale), tick: formatMonthShort(key, settings.locale) }
    return m ? { ...base, value: m.expense_uzs, part: m.one_off_uzs, note: monthNote(m), strong: key === data.month } : { ...base, value: 0, off: true }
  })
})
const monthIdle = computed(() => {
  const data = analytics.value
  const current = data?.months.find((m) => m.month === data.month)
  return {
    label: formatMonthTitle(month.value, settings.locale),
    amount: current?.expense_uzs ?? 0,
    note: (data?.months.length ?? 0) > 1 ? t('analytics.monthsHint') : current ? monthNote(current) : '',
  }
})

/** The week and the day, one-offs left out. */
const whenMode = ref<'week' | 'day'>('week')
const whenModes = computed(() => (['week', 'day'] as const).map((value) => ({ value, label: t(`analytics.whenModes.${value}`) })))
const hasRegular = computed(() => !!analytics.value?.weekdays.some((w) => w.total_uzs > 0))

const weekPeak = computed(() => (analytics.value ? peakWeekday(analytics.value.weekdays) : null))
const weekBars = computed<ScrubBar[]>(() =>
  weekdayAverages(analytics.value?.weekdays ?? []).map((w) => ({
    key: String(w.weekday),
    value: w.average,
    label: t(`analytics.weekdays.${w.weekday}`),
    note: w.count ? t('analytics.weekNote', { ops: t('analytics.operations', w.count) }) : t('analytics.weekNoteNone'),
    tick: t(`analytics.weekdaysShort.${w.weekday}`),
    strong: weekPeak.value?.weekday === w.weekday,
    off: !w.days,
  })),
)
const weekIdle = computed(() =>
  weekPeak.value
    ? {
        label: t('analytics.weekPeak', { on: t(`analytics.weekdaysOn.${weekPeak.value.weekday}`) }),
        amount: weekPeak.value.average,
        note: t('analytics.weekNote', { ops: t('analytics.operations', weekPeak.value.count) }),
      }
    : { label: t('analytics.weekIdle'), amount: stats.value?.perDay ?? 0, note: '' },
)

const hh = (hour: number) => `${String(hour % 24).padStart(2, '0')}:00`
const hourPeak = computed(() => (analytics.value ? peakHours(analytics.value.hours) : null))
const inPeak = (hour: number) => !!hourPeak.value && (hour - hourPeak.value.from + 24) % 24 < 3
const hourBars = computed<ScrubBar[]>(() =>
  (analytics.value?.hours ?? []).map((h) => ({
    key: String(h.hour),
    value: h.total_uzs,
    label: t('analytics.hourLabel', { from: hh(h.hour), to: hh(h.hour + 1) }),
    note: h.count ? t('analytics.operations', h.count) : t('analytics.weekNoteNone'),
    tick: h.hour % 6 === 0 ? String(h.hour) : undefined,
    strong: inPeak(h.hour),
  })),
)
const hourIdle = computed(() => {
  const hours = analytics.value?.hours ?? []
  const peak = hourPeak.value
  if (!peak) return { label: t('analytics.hourIdle'), amount: hours.reduce((sum, h) => sum + h.total_uzs, 0), note: t('analytics.hourHint') }
  return {
    label: t('analytics.hourPeak', { from: hh(peak.from), to: hh(peak.to) }),
    amount: hours.filter((h) => inPeak(h.hour)).reduce((sum, h) => sum + h.total_uzs, 0),
    note: t('analytics.hourShare', { share: peak.share }),
  }
})

/** A category opened in «Куда ушли деньги»: its subcategories, and the operations list narrowed to it. */
function toggleCategory(id: number): void {
  filter.category = filter.category === id ? null : id
}
function subcategories(rootId: number, total: number) {
  return (analytics.value ? subcategoriesOf(analytics.value, rootId) : []).map((row) => ({
    ...row,
    name: row.category_id === rootId ? t('quickAdd.wholeCategory') : (money.categoryById.get(row.category_id)?.names[settings.locale] ?? ''),
    share: total ? Math.round((row.total_uzs * 100) / total) : 0,
  }))
}
const filterCategoryName = computed(() => (filter.category ? (money.categoryById.get(filter.category)?.names[settings.locale] ?? '') : ''))

/** On a phone the operations are far down the screen: the way there after picking a day or a category. */
function toOperations(): void {
  document.getElementById('operations')?.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' })
}

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

type Page = { data: Transaction[]; next_cursor: string | null }

function operationsUrl(cursor?: string): string {
  const query = new URLSearchParams({ month: month.value })
  if (filter.day) query.set('day', filter.day)
  if (filter.category) query.set('category_id', String(filter.category))
  if (cursor) query.set('cursor', cursor)
  return `/transactions?${query}`
}

// Only the latest request may fill the list: picking days quickly must not leave an older day on screen.
let request = 0

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  const ticket = ++request
  try {
    const [, monthSummary, page, monthBudget, monthAnalytics] = await Promise.all([
      money.ensureLoaded(),
      api<Summary>(`/summary?month=${month.value}`),
      api<Page>(operationsUrl()),
      plan.loadBudget(month.value),
      api<MonthAnalytics>(`/analytics?month=${month.value}`).catch(() => null),
    ])
    summary.value = monthSummary
    budget.value = monthBudget
    analytics.value = monthAnalytics
    if (ticket === request) {
      transactions.value = page.data
      nextCursor.value = page.next_cursor
    }
    shownMonth.value = month.value
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

async function loadOperations(): Promise<void> {
  loadingOperations.value = true
  const ticket = ++request
  try {
    const page = await api<Page>(operationsUrl())
    if (ticket !== request) return
    transactions.value = page.data
    nextCursor.value = page.next_cursor
  } catch {
    if (ticket === request) failed.value = true
  } finally {
    if (ticket === request) loadingOperations.value = false
  }
}

async function loadMore(): Promise<void> {
  if (!nextCursor.value) return
  loadingMore.value = true
  try {
    const page = await api<Page>(operationsUrl(nextCursor.value))
    transactions.value.push(...page.data)
    nextCursor.value = page.next_cursor
  } finally {
    loadingMore.value = false
  }
}

onMounted(load)
watch(month, () => {
  filter.day = null
  filter.category = null
  void load()
})
watch(() => money.revision, load)
// A new month clears the filter and loads everything itself.
const opsPane = ref<HTMLElement | null>(null)
watch(
  () => [filter.day, filter.category],
  () => {
    opsPane.value?.scrollTo({ top: 0 })
    if (shownMonth.value === month.value) void loadOperations()
  },
)
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
              <CategoryStrip :rows="spending.rows.map((row) => ({ id: row.category_id, color: row.category?.color, total: row.total }))" />
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

            <!-- The month as an instrument: day by day, as a running total against the month before, months side by side -->
            <section v-if="analytics && hasCharts" class="flex flex-col gap-4">
              <div class="flex flex-col gap-3">
                <h2 class="section-title">{{ $t('analytics.title') }}</h2>
                <UiSegmented v-model="chartMode" :options="chartModes" size="sm" :label="$t('analytics.title')" />
              </div>
              <Transition name="fade" mode="out-in">
                <DayBars
                  v-if="chartMode === 'days'"
                  key="days"
                  :days="analytics.days"
                  :today="analytics.today"
                  :tracked="analytics.tracked"
                  :selected="filter.day"
                  :hint="$t('analytics.dayHint')"
                  @select="filter.day = $event"
                />
                <PaceLine v-else-if="chartMode === 'pace'" key="pace" :data="analytics" />
                <ScrubBars v-else key="months" :bars="monthBars" :idle="monthIdle" :label="$t('analytics.modes.months')" clickable @pick="month = $event" />
              </Transition>
              <button v-if="filter.day && chartMode === 'days'" type="button" class="section-link flex items-center gap-1 self-start xl:hidden" @click="toOperations">
                {{ $t('analytics.showOperations') }}<ArrowDown class="size-3.5" :stroke-width="2.4" />
              </button>
              <dl v-if="stats && stats.count" class="grid grid-cols-3 gap-3 border-t border-line pt-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                  <dt class="truncate text-caption text-ink-3">{{ $t('analytics.stats.count') }}</dt>
                  <dd class="num font-semibold">{{ stats.count }}</dd>
                </div>
                <div class="flex min-w-0 flex-col gap-0.5">
                  <dt class="truncate text-caption text-ink-3">{{ $t('analytics.stats.average') }}</dt>
                  <dd><MoneyAmount :amount="stats.average" animate class="font-semibold" /></dd>
                </div>
                <div class="flex min-w-0 flex-col gap-0.5">
                  <dt class="truncate text-caption text-ink-3">{{ $t('analytics.stats.noSpend') }}</dt>
                  <dd class="num font-semibold">{{ stats.noSpend }}</dd>
                </div>
              </dl>
            </section>

            <!-- Spending by category, against the budget; a category opens into its subcategories -->
            <section class="flex flex-col gap-3">
              <div class="flex items-baseline justify-between gap-3">
                <h2 class="section-title">{{ $t('money.byCategory') }}</h2>
                <RouterLink :to="{ name: 'budget' }" class="section-link">{{ budget?.items.length ? $t('money.editBudget') : $t('money.setBudget') }}</RouterLink>
              </div>
              <UiEmpty v-if="!spending.rows.length" :text="$t('money.noSpending')" />
              <CategoryDonut
                v-if="spending.rows.some((row) => row.total > 0)"
                :rows="spending.rows.map((row) => ({ id: row.category_id, name: row.category?.names[settings.locale] ?? '', color: row.category?.color, total: row.total }))"
                :selected="filter.category"
                class="pt-1 pb-2"
                @select="toggleCategory"
              />
              <div v-if="spending.rows.length" class="flex flex-col gap-1">
                <div v-for="row in spending.rows" :key="row.category_id" class="flex flex-col">
                  <button
                    type="button"
                    class="press-soft -mx-2 flex items-center gap-3 rounded-control px-2 py-2 text-left"
                    :class="{ 'bg-surface-2': filter.category === row.category_id }"
                    :aria-expanded="filter.category === row.category_id"
                    @click="toggleCategory(row.category_id)"
                  >
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
                  </button>
                  <Transition name="fade">
                    <div v-if="filter.category === row.category_id" class="flex flex-col gap-2.5 pt-2 pb-3 pl-12">
                      <div v-for="sub in subcategories(row.category_id, row.total)" :key="sub.category_id" class="flex items-baseline justify-between gap-3">
                        <span class="flex min-w-0 flex-col">
                          <span class="truncate text-[0.9375rem] text-ink-2">{{ sub.name }}</span>
                          <span class="num text-caption text-ink-3">{{ $t('analytics.subcategoryOps', { ops: $t('analytics.operations', sub.count), share: sub.share }) }}</span>
                        </span>
                        <MoneyAmount :amount="sub.total_uzs" class="text-[0.9375rem] font-semibold" />
                      </div>
                      <button type="button" class="section-link flex items-center gap-1 self-start xl:hidden" @click="toOperations">
                        {{ $t('analytics.showOperations') }}<ArrowDown class="size-3.5" :stroke-width="2.4" />
                      </button>
                    </div>
                  </Transition>
                </div>
              </div>
            </section>

            <!-- When in the week and the day the money goes -->
            <section v-if="analytics && hasRegular" class="flex flex-col gap-4">
              <div class="flex flex-col gap-3">
                <div class="flex flex-col gap-1">
                  <h2 class="section-title">{{ $t('analytics.when') }}</h2>
                  <p class="text-caption text-ink-3">{{ $t('analytics.whenHint') }}</p>
                </div>
                <UiSegmented v-model="whenMode" :options="whenModes" size="sm" :label="$t('analytics.when')" />
              </div>
              <Transition name="fade" mode="out-in">
                <ScrubBars v-if="whenMode === 'week'" key="week" :bars="weekBars" :idle="weekIdle" :label="$t('analytics.whenModes.week')" />
                <ScrubBars v-else key="day" :bars="hourBars" :idle="hourIdle" :label="$t('analytics.whenModes.day')" size="sm" />
              </Transition>
            </section>

            <!-- The biggest expenses -->
            <section v-if="analytics && analytics.top.length > 1" class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <h2 class="section-title">{{ $t('analytics.top') }}</h2>
                <p class="text-caption text-ink-3">{{ $t('analytics.topHint') }}</p>
              </div>
              <div class="group">
                <TransactionRow v-for="item in analytics.top" :key="item.id" :transaction="item" dated @click="quick.show(item)" />
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
                  :to="{ name: 'account', params: { id: account.id } }"
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

          <!-- Operations, by day; narrowed to a day or a category picked on the left. On a wide screen the
               column stays in view while the charts scroll, so a day or a category picked there shows at once. -->
          <div ref="opsPane" class="page-col ops-pane">
            <section id="operations" class="flex scroll-mt-16 flex-col gap-3">
              <h2 class="section-title">{{ $t('money.operations') }}</h2>
              <div v-if="filter.day || filter.category" class="flex flex-wrap gap-2">
                <button v-if="filter.day" type="button" class="chip chip-on !py-1.5" :aria-label="$t('analytics.clearFilter', { name: formatDayMonth(filter.day, settings.locale) })" @click="filter.day = null">
                  {{ formatDayMonth(filter.day, settings.locale) }}<X class="size-3.5" :stroke-width="2.6" />
                </button>
                <button v-if="filter.category" type="button" class="chip chip-on !py-1.5" :aria-label="$t('analytics.clearFilter', { name: filterCategoryName })" @click="filter.category = null">
                  {{ filterCategoryName }}<X class="size-3.5" :stroke-width="2.6" />
                </button>
              </div>
              <div class="flex flex-col gap-3" :class="{ 'opacity-60 transition-opacity': loadingOperations }">
                <UiEmpty v-if="!transactions.length && (filter.day || filter.category)" :text="$t('analytics.filteredNone')" />
                <UiEmpty v-else-if="!transactions.length" :icon="ReceiptText" :text="$t('money.empty')" tone="money" />
                <div v-for="group in days" :key="group.day" class="flex flex-col">
                  <div class="flex items-baseline justify-between pt-2 pb-1">
                    <span class="text-caption font-semibold text-ink-2">{{ group.heading }}</span>
                    <MoneyAmount v-if="group.spent" :amount="group.spent" sign="expense" class="text-caption text-ink-3" />
                  </div>
                  <div class="group">
                    <TransactionRow v-for="item in group.items" :key="item.id" :transaction="item" @click="quick.show(item)" />
                  </div>
                </div>
                <UiButton v-if="nextCursor" variant="secondary" block :loading="loadingMore" class="mt-1" @click="loadMore">
                  {{ $t('money.more') }}
                </UiButton>
              </div>
            </section>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
@media (width >= 80rem) {
  .ops-pane {
    position: sticky;
    top: 4rem;
    max-height: calc(100dvh - 5.5rem);
    overflow-y: auto;
    /* Room for the rows' tap highlight, which reaches just outside the column. */
    margin-inline: -1rem;
    padding: 0 1rem 1rem;
    scrollbar-width: thin;
    scrollbar-color: var(--c-line) transparent;
  }
}
</style>
