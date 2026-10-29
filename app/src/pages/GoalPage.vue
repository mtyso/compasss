<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Archive, ArchiveRestore, CalendarCheck, Crown, Pencil, Plus, Repeat } from 'lucide-vue-next'
import BuySheet from '@/components/goals/BuySheet.vue'
import ContributeSheet from '@/components/goals/ContributeSheet.vue'
import GoalIcon from '@/components/goals/GoalIcon.vue'
import GoalSheet from '@/components/goals/GoalSheet.vue'
import { useGoalText } from '@/components/goals/useGoalText'
import HabitRow from '@/components/things/HabitRow.vue'
import HabitSheet from '@/components/things/HabitSheet.vue'
import TaskRow from '@/components/things/TaskRow.vue'
import TaskSheet from '@/components/things/TaskSheet.vue'
import { useTaskToggle, useThingActions } from '@/components/things/useThingActions'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiRow from '@/components/ui/UiRow.vue'
import { showToast } from '@/components/ui/toast'
import { isoDate } from '@/lib/dates'
import { formatMoney, formatMonthTitle } from '@/lib/format'
import { daysBetween, daysSooner, forecastAt, limitChangePerDay, sliderRange, statusTone } from '@/lib/goals'
import { haptics } from '@/lib/haptics'
import { useGoals, type GoalDetail, type Lever } from '@/stores/goals'
import { useHabits, type Habit } from '@/stores/habits'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'
import { useTasks, type Task } from '@/stores/tasks'

const route = useRoute()
const router = useRouter()
const goals = useGoals()
const money = useMoney()
const tasks = useTasks()
const habits = useHabits()
const settings = useSettings()
const text = useGoalText()
const { t } = useI18n()
const { logHabit } = useThingActions()

const id = computed(() => Number(route.params.id))
const detail = ref<GoalDetail | null>(null)
const failed = ref(false)

async function load(): Promise<void> {
  try {
    const [one] = await Promise.all([goals.loadOne(id.value), money.ensureLoaded(), tasks.ensureLoaded(), habits.loaded ? null : habits.load()])
    detail.value = one
    failed.value = false
  } catch {
    failed.value = true
  }
}
onMounted(load)
// A contribution or a purchase moves money, which bumps the revision.
watch(() => money.revision, load)

const goal = computed(() => detail.value?.goal ?? null)
const currency = computed(() => goal.value?.currency ?? 'UZS')
const hasMoney = computed(() => !!goal.value && goal.value.type !== 'other')
const active = computed(() => !!goal.value && !goal.value.done_at && !goal.value.archived)
const collecting = computed(() => active.value && hasMoney.value && goal.value!.remaining > 0)
const tone = computed(() => statusTone(goal.value?.status ?? 'open'))
const toneText = { money: 'text-money', habit: 'text-habit', danger: 'text-danger', goal: 'text-goal', neutral: 'text-ink-3' } as const
const debtRate = computed(() => (goal.value?.type === 'debt' ? goal.value.rate : null))
const fmt = (amount: number) => formatMoney(amount, currency.value, settings.locale)

/* ---------- «Как быстрее»: the contribution slider ---------- */

const draft = ref(0)
watch(goal, (g) => (draft.value = g?.monthly ?? 0), { immediate: true })
const range = computed(() => {
  const g = goal.value!
  const base = sliderRange(g.monthly_required, g.monthly, g.remaining, currency.value)
  return { ...base, max: Math.max(base.max, draft.value) }
})
/** Keeping to what the deadline asks for is on time, as on the server: those months are counted in pay months. */
const draftDate = computed(() => {
  const g = goal.value
  if (!g) return null
  const on = forecastAt(g.remaining, draft.value, debtRate.value)
  return on && g.deadline && g.monthly_required > 0 && draft.value >= g.monthly_required && on > g.deadline ? g.deadline : on
})
const draftVsDeadline = computed(() => (goal.value?.deadline && draftDate.value ? daysBetween(draftDate.value, goal.value.deadline) : null))
const draftLimit = computed(() => (goal.value ? limitChangePerDay(goal.value.monthly, draft.value, currency.value, goal.value.fx_rate) : 0))
const savingDraft = ref(false)

function onSlide(event: Event): void {
  const value = Number((event.target as HTMLInputElement).value)
  if (value !== draft.value) haptics.selection()
  draft.value = value
}

async function keepDraft(): Promise<void> {
  if (!goal.value || savingDraft.value) return
  savingDraft.value = true
  try {
    await goals.save({ monthly_amount: draft.value }, goal.value.id)
    await load()
    haptics.notify('success')
    showToast({ message: t('goals.planSaved'), tone: 'success' })
  } catch {
    showToast({ message: t('goals.failed'), tone: 'danger' })
  } finally {
    savingDraft.value = false
  }
}

function leverTitle(lever: Lever): string {
  switch (lever.key) {
    case 'category_cut': {
      const category = money.categoryById.get(lever.category_id ?? 0)
      return t('goals.levers.category_cut', { category: category?.names[settings.locale] ?? '—', percent: lever.percent })
    }
    case 'habit':
      return t('goals.levers.habit', { name: lever.name })
    default:
      return t(`goals.levers.${lever.key}`)
  }
}

function leverDetail(lever: Lever): string {
  const extra = t('goals.levers.extra', { amount: fmt(lever.extra) })
  if (lever.key === 'category_cut') return `${t('goals.levers.usually', { amount: formatMoney(lever.typical_uzs ?? 0, 'UZS', settings.locale) })} · ${extra}`
  if (lever.key === 'habit') return `${t('goals.levers.perWeek', { n: lever.per_week, amount: formatMoney(lever.saves_uzs ?? 0, 'UZS', settings.locale) })} · ${extra}`
  if (lever.key === 'debt_extra' && lever.interest_saved) return `${extra} · ${t('goals.levers.interestSaved', { amount: fmt(lever.interest_saved) })}`
  return extra
}

function leverDays(lever: Lever): number | null {
  const g = goal.value!
  const days = daysSooner(g.remaining, g.monthly, lever.extra, debtRate.value)
  return days === null || days === Infinity ? null : days
}

function tryLever(lever: Lever): void {
  haptics.selection()
  draft.value = goal.value!.monthly + lever.extra
}

/* ---------- Steps and habits ---------- */

const { shown, justDone, toggle } = useTaskToggle(() => tasks.tasks.filter((task) => task.goal_id === id.value))
const steps = computed(() => shown.value.filter((task) => !task.done_at || justDone.value.has(task.id)))
const goalHabits = computed(() => habits.active.filter((habit) => habit.goal_id === id.value))
const today = isoDate()

const taskSheetOpen = ref(false)
const editingTask = ref<Task | null>(null)
const habitSheetOpen = ref(false)
const editingHabit = ref<Habit | null>(null)

function openTask(task: Task | null): void {
  editingTask.value = task
  taskSheetOpen.value = true
}

function openHabit(habit: Habit | null): void {
  editingHabit.value = habit
  habitSheetOpen.value = true
}

/* ---------- History and check-ins ---------- */

const historyMax = computed(() => Math.max(1, ...(detail.value?.history ?? []).map((m) => Math.abs(m.amount))))
const monthShort = (month: string) => formatMonthTitle(month, settings.locale).split(' ')[0]!.slice(0, 3)

const checkinValue = ref<number | null>(null)
watch(goal, (g) => (checkinValue.value = g?.current_value ?? null), { immediate: true })
async function saveCheckin(): Promise<void> {
  if (!goal.value || checkinValue.value === null) return
  try {
    const fresh = await goals.checkin(goal.value.id, today, checkinValue.value)
    if (detail.value) detail.value.goal = fresh
    haptics.notify('success')
    void goals.load()
  } catch {
    showToast({ message: t('goals.failed'), tone: 'danger' })
  }
}

/* ---------- Actions ---------- */

const editOpen = ref(false)
const contributeOpen = ref(false)
const buyOpen = ref(false)

async function makeMain(): Promise<void> {
  const ids = [id.value, ...goals.active.map((g) => g.id).filter((other) => other !== id.value)]
  haptics.impact('light')
  await goals.order(ids)
  await load()
}

async function setArchived(archived: boolean): Promise<void> {
  await goals.save({ archived }, id.value)
  showToast({ message: archived ? t('goals.archived') : t('goals.restored') })
  if (archived) void router.replace({ name: 'goals' })
  else await load()
}

function bought(debt: { id: number } | null): void {
  if (debt) void router.replace({ name: 'goal', params: { id: debt.id } })
  else void load()
}
</script>

<template>
  <div class="page">
    <ScreenHeader :title="goal?.title ?? $t('goals.title')" back="/goals">
      <template #trailing>
        <UiIconButton v-if="goal" :label="$t('goals.edit')" @click="editOpen = true">
          <Pencil class="size-5" :stroke-width="2" />
        </UiIconButton>
      </template>
    </ScreenHeader>

    <div v-if="!detail && !failed" class="flex flex-col gap-4" aria-hidden="true">
      <div class="skeleton h-11 w-64" />
      <div class="skeleton h-2 w-full rounded-full" />
      <div class="skeleton h-32 w-full rounded-card" />
    </div>
    <p v-else-if="failed && !detail" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="goal && detail">
      <!-- Where the goal stands: the sum, the bar, the status and the dates. -->
      <section class="flex flex-col gap-4">
        <div class="flex items-center gap-4">
          <GoalIcon :goal="goal" size="xl" :ring="goal.progress" class="vt-shared" :style="{ '--vt': `goal-${goal.id}` }" />
          <div class="flex min-w-0 flex-col gap-0.5">
            <span class="text-[0.9375rem] font-medium text-ink-2">
              {{ $t(`goals.types.${goal.type}`) }}<template v-if="detail.is_main && active"> · {{ $t('goals.mainGoal') }}</template>
            </span>
            <span class="font-semibold">
              <span :class="toneText[tone]">{{ text.status(goal) }}</span>
              <span class="num text-ink-3"> · {{ Math.min(100, Math.floor(goal.progress * 100)) }}%</span>
            </span>
          </div>
        </div>

        <div v-if="hasMoney" class="flex flex-col gap-1.5">
          <MoneyAmount :amount="goal.saved" :currency="currency" animate class="text-amount leading-none font-semibold tracking-[-0.03em]" />
          <span class="text-caption text-ink-2">
            {{ goal.type === 'debt' ? $t('goals.paidOfShort', { total: fmt(goal.target_amount ?? 0) }) : $t('goals.ofShort', { target: fmt(goal.target_amount ?? 0) }) }}
          </span>
        </div>
        <p v-else class="text-[1.75rem] leading-tight font-semibold">{{ text.amounts(goal) }}</p>

        <dl class="grid grid-cols-2 gap-4 rounded-card bg-surface-2 px-4 py-3">
          <div v-if="goal.deadline" class="flex min-w-0 flex-col gap-0.5">
            <dt class="text-caption text-ink-3">{{ $t('goals.deadlineShort') }}</dt>
            <dd class="truncate font-semibold">{{ text.dayYear(goal.deadline) }}</dd>
          </div>
          <div v-if="hasMoney && collecting" class="flex min-w-0 flex-col gap-0.5">
            <dt class="text-caption text-ink-3">{{ goal.type === 'debt' ? $t('goals.paymentShort') : $t('goals.monthlyShort') }}</dt>
            <dd class="truncate font-semibold"><MoneyAmount :amount="goal.monthly" :currency="currency" /></dd>
          </div>
          <div v-if="goal.forecast.plan_on && collecting" class="flex min-w-0 flex-col gap-0.5">
            <dt class="text-caption text-ink-3">{{ $t('goals.byPlan') }}</dt>
            <dd class="truncate font-semibold">{{ text.dayYear(goal.forecast.plan_on) }}</dd>
          </div>
          <div v-if="goal.forecast.fact_on && active" class="flex min-w-0 flex-col gap-0.5">
            <dt class="text-caption text-ink-3">{{ $t('goals.byFact') }}</dt>
            <dd class="truncate font-semibold">{{ text.dayYear(goal.forecast.fact_on) }}</dd>
          </div>
          <div v-if="!hasMoney" class="flex min-w-0 flex-col gap-0.5">
            <dt class="text-caption text-ink-3">{{ $t('goals.stepsShort') }}</dt>
            <dd class="truncate font-semibold">{{ goal.steps.done }}/{{ goal.steps.total }}</dd>
          </div>
        </dl>
      </section>

      <!-- This period and the main actions. -->
      <section v-if="collecting" class="card flex flex-col gap-3 p-4" data-panel>
        <p class="text-[0.9375rem]">
          <template v-if="goal.period.due > 0">
            {{ $t('goals.periodDue') }} <MoneyAmount :amount="goal.period.due" :currency="currency" class="font-semibold" />
          </template>
          <template v-else-if="goal.period.planned > 0">{{ $t('goals.periodMade') }}</template>
          <template v-else>{{ $t('goals.periodNone') }}</template>
        </p>
        <UiButton v-if="goal.period.due > 0" @click="contributeOpen = true">{{ goal.type === 'debt' ? $t('goals.pay') : $t('goals.contribute') }}</UiButton>
        <UiButton v-else variant="secondary" size="sm" class="self-start !bg-bg" @click="contributeOpen = true">{{ goal.type === 'debt' ? $t('goals.payMore') : $t('goals.contributeMore') }}</UiButton>
      </section>
      <section v-if="goal.type === 'buy' && goal.status === 'reached' && active" class="card flex flex-col gap-3 p-4" data-panel>
        <p class="font-medium">{{ goal.installment ? $t('goals.downReached') : $t('goals.reachedBuy') }}</p>
        <UiButton @click="buyOpen = true">{{ $t('goals.buy') }}</UiButton>
      </section>

      <!-- A purchase on installment: what comes after the down payment. -->
      <section v-if="goal.installment" class="flex flex-col gap-3">
        <h2 class="section-title">{{ $t('goals.installmentTitle') }}</h2>
        <dl class="flex flex-col gap-2 text-[0.9375rem]">
          <div class="flex justify-between gap-3"><dt class="text-ink-2">{{ $t('goals.price') }}</dt><dd><MoneyAmount :amount="goal.installment.price" :currency="currency" class="font-semibold" /></dd></div>
          <div class="flex justify-between gap-3"><dt class="text-ink-2">{{ $t('goals.downOf', { percent: goal.installment.down_percent }) }}</dt><dd><MoneyAmount :amount="goal.installment.down_amount" :currency="currency" class="font-semibold" /></dd></div>
          <div class="flex justify-between gap-3"><dt class="text-ink-2">{{ $t('goals.loanFor', { n: goal.installment.months ?? '—' }) }}</dt><dd><MoneyAmount :amount="goal.installment.loan" :currency="currency" class="font-semibold" /></dd></div>
          <div v-if="goal.installment.payment" class="flex justify-between gap-3"><dt class="text-ink-2">{{ $t('goals.paymentMonthly') }}</dt><dd><MoneyAmount :amount="goal.installment.payment" :currency="currency" class="font-semibold" /></dd></div>
          <div v-if="goal.installment.overpay !== null" class="flex justify-between gap-3">
            <dt class="text-ink-2">{{ goal.installment.rate ? $t('goals.overpayAt', { rate: goal.installment.rate }) : $t('goals.overpay') }}</dt>
            <dd><MoneyAmount :amount="goal.installment.overpay" :currency="currency" class="font-semibold" :class="{ 'text-habit': goal.installment.overpay > 0 }" /></dd>
          </div>
        </dl>
        <p v-if="goal.installment.payment_uzs && goal.monthly_uzs" class="text-caption text-ink-2">
          {{ goal.installment.payment_uzs <= goal.monthly_uzs ? $t('goals.paymentFits', { amount: fmt(goal.monthly) }) : $t('goals.paymentHeavier', { amount: fmt(goal.monthly) }) }}
        </p>
        <div v-if="goal.installment.options.length && goal.installment.overpay !== null" class="group">
          <UiRow
            v-for="option in goal.installment.options"
            :key="option.down_percent"
            :title="$t('goals.downOption', { percent: option.down_percent, amount: fmt(option.down_amount) })"
            :subtitle="option.payment ? (goal.installment.overpay ? $t('goals.downOptionDetail', { payment: fmt(option.payment), saved: fmt(goal.installment.overpay - (option.overpay ?? 0)) }) : $t('goals.downOptionPayment', { payment: fmt(option.payment) })) : undefined"
            :chevron="false"
          />
        </div>
        <p v-if="goal.installment.overpay === 0 && goal.installment.options.length" class="text-caption text-ink-2">{{ $t('goals.noInterestHint') }}</p>
      </section>

      <!-- «Как быстрее»: more a month, and where it could come from. -->
      <section v-if="collecting && detail.speed" class="flex flex-col gap-3">
        <h2 class="section-title">{{ $t('goals.faster') }}</h2>
        <div class="flex flex-col gap-3 rounded-card bg-surface-2 p-4" data-panel>
          <div class="flex items-baseline justify-between gap-3">
            <span class="text-[0.9375rem] text-ink-2">{{ goal.type === 'debt' ? $t('goals.payMonthly') : $t('goals.putMonthly') }}</span>
            <MoneyAmount :amount="draft" :currency="currency" class="text-xl font-semibold" />
          </div>
          <input
            type="range"
            class="goal-slider w-full"
            :min="range.min"
            :max="range.max"
            :step="range.step"
            :value="draft"
            :aria-label="$t('goals.putMonthly')"
            @input="onSlide"
          />
          <div class="flex flex-col gap-1 text-[0.9375rem]">
            <p v-if="draftDate">
              {{ $t('goals.reachOn', { date: text.dayYear(draftDate) }) }}
              <span v-if="draftVsDeadline !== null" :class="draftVsDeadline >= 0 ? 'text-money' : 'text-danger'">
                · {{ draftVsDeadline === 0 ? $t('goals.onDeadline') : draftVsDeadline > 0 ? $t('goals.beforeDeadline', draftVsDeadline) : $t('goals.afterDeadline', -draftVsDeadline) }}
              </span>
            </p>
            <p v-else class="text-danger">{{ $t('goals.never') }}</p>
            <p v-if="draftLimit !== 0" class="text-caption text-ink-2">
              {{ $t(draftLimit > 0 ? 'goals.limitUp' : 'goals.limitDown', { amount: formatMoney(Math.abs(draftLimit), 'UZS', settings.locale) }) }}
            </p>
          </div>
          <UiButton v-if="draft !== goal.monthly" size="sm" :loading="savingDraft" @click="keepDraft">
            {{ $t('goals.keepDraft', { amount: fmt(draft) }) }}
          </UiButton>
        </div>

        <div v-if="detail.speed.levers.length" class="group">
          <UiRow v-for="(lever, index) in detail.speed.levers" :key="index" :chevron="false" @click="tryLever(lever)">
            <!-- Levers are sentences, so they wrap instead of being cut. -->
            <span class="block whitespace-normal">{{ leverTitle(lever) }}</span>
            <template #subtitle><span class="block whitespace-normal">{{ leverDetail(lever) }}</span></template>
            <template #trailing>
              <span v-if="leverDays(lever)" class="num text-sm font-semibold text-money">{{ $t('goals.daysSooner', leverDays(lever)!) }}</span>
            </template>
          </UiRow>
        </div>
        <p v-else class="text-caption text-ink-3">{{ $t('goals.noLevers') }}</p>
      </section>

      <!-- A goal measured by a number: today's value. -->
      <section v-if="!hasMoney && goal.target_value !== null && active" class="flex flex-col gap-3">
        <h2 class="section-title">{{ $t('goals.checkinTitle') }}</h2>
        <div class="flex gap-2">
          <label class="field flex flex-1 items-baseline gap-2">
            <input v-model.number="checkinValue" type="number" inputmode="decimal" class="num min-w-0 flex-1 bg-transparent text-lg font-semibold outline-none" :aria-label="$t('goals.checkinTitle')" />
            <span class="text-sm text-ink-3">{{ goal.unit }}</span>
          </label>
          <UiButton variant="secondary" @click="saveCheckin">{{ $t('goals.checkinSave') }}</UiButton>
        </div>
      </section>

      <section class="flex flex-col gap-3">
        <h2 class="section-title">{{ $t('goals.steps') }}</h2>
        <div class="group relative">
          <TaskRow v-for="task in steps" :key="task.id" :task="task" :today="today" @toggle="toggle(task)" @open="openTask(task)" />
          <UiRow :title="$t('goals.addStep')" :subtitle="steps.length ? undefined : $t('goals.stepsHint')" tone="muted" :chevron="false" @click="openTask(null)">
            <template #leading><span class="grid size-[22px] place-items-center text-ink-3"><Plus class="size-5" :stroke-width="2.2" /></span></template>
          </UiRow>
        </div>
      </section>

      <section class="flex flex-col gap-3">
        <h2 class="section-title">{{ $t('goals.habits') }}</h2>
        <div class="group">
          <HabitRow v-for="habit in goalHabits" :key="habit.id" :habit="habit" compact @tap="logHabit(habit)" @hold="openHabit(habit)" @open="router.push({ name: 'habit', params: { id: habit.id } })" />
          <UiRow :title="$t('goals.addHabit')" :subtitle="goalHabits.length ? undefined : $t('goals.habitsHint')" tone="muted" :chevron="false" @click="openHabit(null)">
            <template #leading><span class="grid size-[22px] place-items-center text-ink-3"><Repeat class="size-5" :stroke-width="2.2" /></span></template>
          </UiRow>
        </div>
      </section>

      <section v-if="detail.history.length > 1" class="flex flex-col gap-3">
        <h2 class="section-title">{{ goal.type === 'debt' ? $t('goals.historyDebt') : $t('goals.history') }}</h2>
        <div class="flex h-28 items-end gap-2" role="img" :aria-label="$t('goals.history')">
          <div v-for="month in detail.history" :key="month.month" class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5">
            <span class="w-full rounded-md" :class="month.amount >= 0 ? 'bg-goal' : 'bg-danger'" :style="{ height: `${Math.max(3, (Math.abs(month.amount) / historyMax) * 80)}%` }" />
            <span class="text-micro text-ink-3">{{ monthShort(month.month) }}</span>
          </div>
        </div>
      </section>

      <div class="group">
        <UiRow v-if="active && !detail.is_main" :title="$t('goals.makeMain')" :subtitle="$t('goals.makeMainHint')" :chevron="false" @click="makeMain">
          <template #leading><Crown class="size-[22px]" :stroke-width="1.9" /></template>
        </UiRow>
        <UiRow v-if="!goal.archived" :title="$t('goals.archive')" :chevron="false" @click="setArchived(true)">
          <template #leading><Archive class="size-[22px]" :stroke-width="1.9" /></template>
        </UiRow>
        <UiRow v-else :title="$t('goals.unarchive')" :chevron="false" @click="setArchived(false)">
          <template #leading><ArchiveRestore class="size-[22px]" :stroke-width="1.9" /></template>
        </UiRow>
        <UiRow v-if="goal.done_at" :title="$t('goals.doneOn', { date: text.dayYear(goal.done_at.slice(0, 10)) })" :chevron="false">
          <template #leading><CalendarCheck class="size-[22px]" :stroke-width="1.9" /></template>
        </UiRow>
      </div>

      <GoalSheet v-model:open="editOpen" :goal="goal" @saved="load" @deleted="router.replace({ name: 'goals' })" />
      <ContributeSheet v-model:open="contributeOpen" :goal="goal" />
      <BuySheet v-if="goal.type === 'buy'" v-model:open="buyOpen" :goal="goal" @bought="bought" />
      <TaskSheet v-model:open="taskSheetOpen" :task="editingTask" :draft="{ goal_id: goal.id }" />
      <HabitSheet v-model:open="habitSheetOpen" :habit="editingHabit" :draft-goal-id="goal.id" />
    </template>
  </div>
</template>

<style scoped>
/* The slider in the goal's colour, a large thumb for the thumb. */
.goal-slider {
  appearance: none;
  height: 2.25rem;
  background: transparent;
  accent-color: var(--c-goal);
}

.goal-slider::-webkit-slider-runnable-track {
  height: 0.375rem;
  border-radius: 9999px;
  background: color-mix(in srgb, var(--c-ink) 10%, transparent);
}

.goal-slider::-webkit-slider-thumb {
  appearance: none;
  width: 1.75rem;
  height: 1.75rem;
  margin-top: -0.6875rem;
  border-radius: 9999px;
  background: var(--c-goal);
  box-shadow: 0 0 0 4px var(--c-bg);
}

.goal-slider::-moz-range-track {
  height: 0.375rem;
  border-radius: 9999px;
  background: color-mix(in srgb, var(--c-ink) 10%, transparent);
}

.goal-slider::-moz-range-thumb {
  width: 1.75rem;
  height: 1.75rem;
  border: none;
  border-radius: 9999px;
  background: var(--c-goal);
}
</style>
