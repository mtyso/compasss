<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowDownRight, ArrowUpRight, ChevronLeft, ChevronRight, CircleCheck, ReceiptText, Target } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import DayBars from '@/components/money/DayBars.vue'
import GoalIcon from '@/components/goals/GoalIcon.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiProgress from '@/components/ui/UiProgress.vue'
import { api } from '@/lib/api'
import { isoDate, isoMonth, shiftMonth } from '@/lib/dates'
import { formatMoney, formatMonthTitle } from '@/lib/format'
import { againstUsual, movers, usualNow, type MonthReport, type ReportGoal } from '@/lib/report'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

/**
 * «Итоги месяца» (docs/PLAN.md, D30): what was kept of the income, spending against the usual level and
 * the budget, and how the goals moved. Last month by default; the arrows go through the months the app saw.
 */
const route = useRoute()
const router = useRouter()
const money = useMoney()
const settings = useSettings()
const { t } = useI18n()

const report = ref<MonthReport | null>(null)
const loading = ref(false)
const failed = ref(false)

const current = isoMonth()
const month = computed(() => (typeof route.params.month === 'string' && route.params.month ? route.params.month : shiftMonth(current, -1)))
const title = computed(() => formatMonthTitle(month.value, settings.locale))
const canGoBack = computed(() => !!report.value?.first_month && month.value > report.value.first_month)

function go(step: number): void {
  void router.replace({ name: 'report', params: { month: shiftMonth(month.value, step) } })
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [next] = await Promise.all([api<MonthReport>(`/reports/month?month=${month.value}`), money.ensureLoaded()])
    report.value = next
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}
watch(month, load, { immediate: true })

const uzs = (value: number) => formatMoney(value, 'UZS', settings.locale)
const category = (id: number) => money.categoryById.get(id)
const empty = computed(() => !!report.value && !report.value.income_uzs && !report.value.expense_uzs && !report.value.goals.length)

const spentSomeDay = computed(() => !!report.value?.days?.some((day) => day.expense_uzs > 0))

const vsUsual = computed(() => (report.value ? againstUsual(report.value) : null))
const regular = computed(() => (report.value ? report.value.expense_uzs - report.value.one_off_uzs : 0))
const usual = computed(() => (report.value ? usualNow(report.value) : null))
const barMax = computed(() => Math.max(1, regular.value, usual.value ?? 0))
const changes = computed(() => movers(report.value?.categories ?? [], report.value?.pace ?? 1))

/** Categories with spending this month or a limit; the bar is against the limit, or the biggest category. */
const rows = computed(() => {
  const list = (report.value?.categories ?? []).filter((c) => c.total_uzs > 0 || c.limit_uzs !== null)
  const max = Math.max(1, ...list.map((c) => c.total_uzs))
  return list.map((c) => ({
    ...c,
    category: category(c.category_id),
    over: c.limit_uzs !== null && c.total_uzs > c.limit_uzs,
    share: c.limit_uzs ? c.total_uzs / c.limit_uzs : c.total_uzs / max,
  }))
})

function categoryLine(row: (typeof rows.value)[number]): string {
  if (row.over) return t('report.overBy', { amount: uzs(row.total_uzs - row.limit_uzs!) })
  const parts = [
    row.limit_uzs !== null ? t('report.limitOf', { amount: uzs(row.limit_uzs) }) : null,
    row.one_off_uzs > 0 && row.one_off_uzs === row.total_uzs ? t('report.oneOffShort') : null,
    row.usual_uzs ? t('report.usualShort', { amount: uzs(row.usual_uzs) }) : null,
  ]
  return parts.filter(Boolean).join(' · ')
}

/** «Отложено 4 000 000 сум · на долги 800 000 сум» */
const goalsLine = computed(() => {
  const r = report.value
  if (!r) return ''
  const added = r.goals_added_uzs ? t('report.goalsAdded', { amount: uzs(r.goals_added_uzs) }) : null
  const paid = r.debts_paid_uzs ? t(added ? 'report.debtsPaid' : 'report.debtsPaidAlone', { amount: uzs(r.debts_paid_uzs) }) : null
  return [added, paid].filter(Boolean).join(' · ')
})

const goalMoney = (goal: ReportGoal, value: number) => formatMoney(value, goal.currency, settings.locale)
function goalLine(goal: ReportGoal): string {
  const percent = (value: number) => `${Math.round(value * 100)}%`
  if (goal.added <= 0) return t('report.goalNone')
  return `${percent(goal.progress_from)} → ${percent(goal.progress_to)}`
}
</script>

<template>
  <div class="page page-wide">
    <ScreenHeader :title="title" :overline="$t('report.title')" back="/money">
      <template #trailing>
        <div class="-mr-2 flex items-center">
          <UiIconButton size="sm" :label="$t('money.prevMonth')" :disabled="!canGoBack" @click="go(-1)"><ChevronLeft class="size-5" /></UiIconButton>
          <UiIconButton size="sm" :label="$t('money.nextMonth')" :disabled="month >= current" @click="go(1)"><ChevronRight class="size-5" /></UiIconButton>
        </div>
      </template>
    </ScreenHeader>

    <div v-if="!report && loading" class="flex flex-col gap-4" aria-busy="true">
      <div class="skeleton h-4 w-24" />
      <div class="skeleton h-11 w-56" />
      <div class="skeleton h-16 rounded-card" />
      <div class="skeleton h-40 rounded-card" />
    </div>
    <p v-else-if="failed && !report" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <Transition v-else-if="report" name="fade" mode="out-in">
      <div :key="report.month" class="flex flex-col gap-(--page-gap)" :class="{ 'opacity-60 transition-opacity': loading }">
        <p v-if="failed" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>
        <UiEmpty v-if="empty" :icon="ReceiptText" :text="$t('report.empty')" />

        <div v-else class="page-columns">
          <div class="page-col">
            <!-- What was kept of the income -->
            <section class="flex flex-col gap-4">
              <p v-if="!report.over || !report.tracked" class="-mb-1 text-caption text-ink-3">
                {{ !report.over ? $t('report.soFar') : $t('report.partial') }}
              </p>
              <div class="flex flex-col gap-1.5">
                <span class="text-[0.9375rem] font-medium text-ink-2">{{ report.saved_uzs >= 0 ? $t('report.saved') : $t('report.overspent') }}</span>
                <MoneyAmount
                  :amount="Math.abs(report.saved_uzs)"
                  animate
                  class="text-amount leading-none font-semibold"
                  :class="report.saved_uzs >= 0 ? 'text-money' : 'text-danger'"
                />
                <span v-if="report.rate !== null && report.rate >= 0" class="text-caption text-ink-3">
                  <span class="font-semibold text-ink">{{ $t('report.ofIncome', { rate: report.rate }) }}</span>
                  <template v-if="report.prev?.rate != null"> · {{ $t('report.prevRate', { rate: report.prev.rate }) }}</template>
                </span>
              </div>
              <dl class="grid grid-cols-2 gap-4 rounded-card bg-surface-2 px-4 py-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                  <dt class="text-caption text-ink-3">{{ $t('report.income') }}</dt>
                  <dd><MoneyAmount :amount="report.income_uzs" class="font-semibold text-money" /></dd>
                </div>
                <div class="flex min-w-0 flex-col gap-0.5">
                  <dt class="text-caption text-ink-3">{{ $t('report.expense') }}</dt>
                  <dd><MoneyAmount :amount="report.expense_uzs" class="font-semibold" /></dd>
                  <dd v-if="report.one_off_uzs" class="text-caption text-ink-3">{{ $t('report.oneOff', { amount: uzs(report.one_off_uzs) }) }}</dd>
                </div>
              </dl>
            </section>

            <!-- The month day by day -->
            <section v-if="spentSomeDay" class="flex flex-col gap-3">
              <h2 class="section-title">{{ $t('report.byDay') }}</h2>
              <DayBars :days="report.days" :today="report.over ? null : isoDate()" :tracked="report.tracked" />
            </section>

            <!-- Spending against the usual level -->
            <section v-if="report.usual_uzs !== null" class="flex flex-col gap-3">
              <h2 class="section-title">{{ $t('report.vsUsual') }}</h2>
              <div class="card flex flex-col gap-4 px-4 py-4">
                <p class="text-[1.25rem] leading-tight font-semibold tracking-[-0.02em]" :class="{ 'text-money': (vsUsual ?? 0) < 0, 'text-danger': (vsUsual ?? 0) > 0 }">
                  {{ !vsUsual ? $t('report.same') : vsUsual < 0 ? $t('report.less', { n: -vsUsual }) : $t('report.more', { n: vsUsual }) }}
                </p>
                <div class="flex flex-col gap-3">
                  <div class="flex flex-col gap-1.5">
                    <div class="flex items-baseline justify-between gap-3 text-[0.9375rem]">
                      <span>{{ report.over ? $t('report.thisMonth') : $t('report.soFarShort') }}</span>
                      <MoneyAmount :amount="regular" class="font-semibold" />
                    </div>
                    <UiProgress :value="regular / barMax" :tone="(vsUsual ?? 0) > 0 ? 'habit' : 'money'" />
                  </div>
                  <div class="flex flex-col gap-1.5">
                    <div class="flex items-baseline justify-between gap-3 text-[0.9375rem] text-ink-2">
                      <span>{{ report.over ? $t('report.usual') : $t('report.usualByNow') }}</span>
                      <MoneyAmount :amount="usual ?? 0" class="font-semibold" />
                    </div>
                    <UiProgress :value="(usual ?? 0) / barMax" tone="neutral" />
                  </div>
                </div>
                <p class="text-caption text-ink-3">{{ $t('report.usualHint') }}</p>
              </div>

              <div v-for="side in (['up', 'down'] as const)" :key="side" class="flex flex-col gap-1">
                <template v-if="changes[side].length">
                  <h3 class="pt-2 text-sm font-medium text-ink-2">{{ $t(`report.${side}`) }}</h3>
                  <div v-for="m in changes[side]" :key="m.category_id" class="flex items-center gap-3 py-2">
                    <CategoryIcon :icon="category(m.category_id)?.icon" :color="category(m.category_id)?.color" size="sm" />
                    <div class="flex min-w-0 flex-1 flex-col">
                      <span class="truncate text-[0.9375rem]">{{ category(m.category_id)?.names[settings.locale] }}</span>
                      <span class="truncate text-caption text-ink-3">{{ $t('report.usualShort', { amount: uzs(m.usual) }) }}</span>
                    </div>
                    <span class="num flex shrink-0 items-center gap-0.5 text-[0.9375rem] font-semibold" :class="side === 'up' ? 'text-danger' : 'text-money'">
                      <component :is="side === 'up' ? ArrowUpRight : ArrowDownRight" class="size-4" :stroke-width="2.4" />
                      {{ uzs(Math.abs(m.diff)) }}
                    </span>
                  </div>
                </template>
              </div>
            </section>

            <!-- Every category, against its budget -->
            <section v-if="rows.length" class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <h2 class="section-title">{{ $t('report.byCategory') }}</h2>
                <p v-if="report.budget" class="text-caption font-medium" :class="report.budget.kept === report.budget.count ? 'text-money' : 'text-ink-3'">
                  {{ $t(report.budget.kept === report.budget.count ? (report.over ? 'report.budgetAll' : 'report.budgetAllSoFar') : 'report.budgetKept', report.budget) }}
                </p>
              </div>
              <div class="flex flex-col gap-4 pt-1">
                <div v-for="row in rows" :key="row.category_id" class="flex items-center gap-3">
                  <CategoryIcon :icon="row.category?.icon" :color="row.category?.color" size="sm" />
                  <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <div class="flex items-baseline justify-between gap-2">
                      <span class="truncate text-[0.9375rem]">{{ row.category?.names[settings.locale] }}</span>
                      <MoneyAmount :amount="row.total_uzs" class="text-[0.9375rem] font-semibold" :class="{ 'text-danger': row.over }" />
                    </div>
                    <UiProgress size="sm" :value="row.share" :tone="row.over ? 'danger' : row.limit_uzs !== null ? 'money' : 'neutral'" />
                    <span v-if="categoryLine(row)" class="text-caption" :class="row.over ? 'font-medium text-danger' : 'text-ink-3'">{{ categoryLine(row) }}</span>
                  </div>
                </div>
              </div>
            </section>
          </div>

          <!-- How the goals moved -->
          <div class="page-col">
            <section class="flex flex-col gap-3">
              <div class="flex flex-col gap-1">
                <h2 class="section-title">{{ $t('report.goals') }}</h2>
                <p v-if="goalsLine" class="text-caption text-ink-3">{{ goalsLine }}</p>
              </div>
              <UiEmpty v-if="!report.goals.length" :icon="Target" :text="$t('report.noGoals')" />
              <div v-else class="group">
                <RouterLink v-for="goal in report.goals" :key="goal.id" :to="{ name: 'goal', params: { id: goal.id } }" class="press-soft flex items-center gap-3 px-4 py-3.5">
                  <GoalIcon :goal="{ icon: goal.icon, type: goal.type, done_at: null }" size="sm" />
                  <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <div class="flex items-baseline justify-between gap-2">
                      <span class="truncate font-medium">{{ goal.title }}</span>
                      <span v-if="goal.added > 0" class="num shrink-0 text-[0.9375rem] font-semibold text-goal">+{{ goalMoney(goal, goal.added) }}</span>
                    </div>
                    <!-- Where the goal was, and what this month added to it. -->
                    <div class="relative h-1.5 overflow-hidden rounded-full bg-ink/[0.07]">
                      <div class="absolute inset-y-0 left-0 rounded-full bg-goal/35" :style="{ width: `${goal.progress_from * 100}%` }" />
                      <div class="absolute inset-y-0 rounded-full bg-goal" :style="{ left: `${goal.progress_from * 100}%`, width: `${Math.max(0, goal.progress_to - goal.progress_from) * 100}%` }" />
                    </div>
                    <span class="flex items-center gap-1.5 text-caption" :class="goal.done ? 'font-medium text-money' : 'text-ink-3'">
                      <template v-if="goal.done"><CircleCheck class="size-3.5" :stroke-width="2.4" />{{ goal.type === 'debt' ? $t('report.paidOff') : $t('report.reached') }}</template>
                      <template v-else>{{ goalLine(goal) }}</template>
                    </span>
                  </div>
                </RouterLink>
              </div>
            </section>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>
