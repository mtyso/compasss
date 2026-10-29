<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Lightbulb, Plus, Repeat, Scale, Settings, Target, Wallet } from 'lucide-vue-next'
import InsightCard from '@/components/advisor/InsightCard.vue'
import TodayPlan from '@/components/calendar/TodayPlan.vue'
import ContributeSheet from '@/components/goals/ContributeSheet.vue'
import GoalRow from '@/components/goals/GoalRow.vue'
import SectionBlock from '@/components/SectionBlock.vue'
import HabitRow from '@/components/things/HabitRow.vue'
import HabitSheet from '@/components/things/HabitSheet.vue'
import HabitValueSheet from '@/components/things/HabitValueSheet.vue'
import TaskRow from '@/components/things/TaskRow.vue'
import TaskSheet from '@/components/things/TaskSheet.vue'
import { useTaskToggle, useThingActions } from '@/components/things/useThingActions'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiRow from '@/components/ui/UiRow.vue'
import LimitCard from '@/components/plan/LimitCard.vue'
import MoneyStreakLine from '@/components/plan/MoneyStreakLine.vue'
import WeekSummaryCard from '@/components/plan/WeekSummaryCard.vue'
import PaySheet from '@/components/plan/PaySheet.vue'
import PaymentRow from '@/components/plan/PaymentRow.vue'
import { isoDate } from '@/lib/dates'
import { formatDayHeading, formatDayMonth } from '@/lib/format'
import { groupTasks } from '@/lib/tasks'
import { useGoals } from '@/stores/goals'
import { useHabits, type Habit } from '@/stores/habits'
import { useMoney } from '@/stores/money'
import { usePlan, type RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'
import { useTasks, type Task } from '@/stores/tasks'

const settings = useSettings()
const money = useMoney()
const plan = usePlan()
const router = useRouter()
const failed = ref(false)

// A payment's row opens «Оплачено» with the details: another sum, account or day, or «Пропустить».
const paySheetOpen = ref(false)
const paying = ref<RecurringPayment | null>(null)
function openPayment(payment: RecurringPayment): void {
  paying.value = payment
  paySheetOpen.value = true
}

const heading = computed(() => formatDayHeading(new Date(), settings.locale))
const today = computed(() => plan.today)

async function load(): Promise<void> {
  try {
    await Promise.all([plan.loadToday(), money.ensureLoaded()])
    failed.value = false
  } catch {
    failed.value = true
  }
}

onMounted(load)
watch(() => money.revision, load)

// The goals come with «Сегодня»: the main ones here, the period's contribution and «Отложить».
const goals = useGoals()
watch(
  () => plan.today?.goals,
  (next) => next && goals.apply(next),
)
const topGoals = computed(() => {
  const list = today.value?.goals.goals.filter((g) => !g.done_at) ?? []
  return list.slice(0, 2)
})
const contributeOpen = ref(false)
/** The goals panel already asks to put money aside, so its reminder card would say the same thing twice. */
const insights = computed(() => (today.value?.goals.due_uzs ? today.value.insights.filter((i) => i.rule !== 'goal_due') : (today.value?.insights ?? [])))

// Habits and tasks come from their own stores, so a tick here and on «Дела» is the same tick.
const tasks = useTasks()
const habits = useHabits()
const { logHabit } = useThingActions()
const day = isoDate()
const thingsFailed = ref(false)

onMounted(async () => {
  try {
    await Promise.all([tasks.load(), habits.load()])
    thingsFailed.value = false
  } catch {
    thingsFailed.value = true
  }
})

const { shown, justDone, toggle } = useTaskToggle(() => tasks.tasks)
const dueTasks = computed(() => groupTasks(shown.value, 'today', day, justDone.value).flatMap((group) => group.tasks))
const laterCount = computed(() => tasks.tasks.filter((task) => !task.done_at && (task.due_on === null || task.due_on > day)).length)
const habitsDone = computed(() => habits.forToday.filter((h) => h.today.state === 'done').length)

const taskSheetOpen = ref(false)
const editingTask = ref<Task | null>(null)
const habitSheetOpen = ref(false)
const editingHabit = ref<Habit | null>(null)
const valueSheetOpen = ref(false)
const valueHabit = ref<Habit | null>(null)

function openTask(task: Task | null): void {
  editingTask.value = task && (tasks.byId.get(task.id) ?? task)
  taskSheetOpen.value = true
}

function openHabit(habit: Habit): void {
  editingHabit.value = habit
  habitSheetOpen.value = true
}

function adjustHabit(habit: Habit): void {
  if (habit.type === 'check') return openHabit(habit)
  valueHabit.value = habit
  valueSheetOpen.value = true
}

function saveValue(value: number): void {
  const habit = valueHabit.value && habits.habits.find((h) => h.id === valueHabit.value!.id)
  if (habit) void logHabit(habit, value)
}
</script>

<template>
  <div class="page page-wide">
    <ScreenHeader :title="$t('today.title')" :overline="heading">
      <template #trailing>
        <UiIconButton :label="$t('ui.settings')" class="md:hidden" @click="router.push({ name: 'more' })">
          <Settings class="size-[22px]" :stroke-width="1.9" />
        </UiIconButton>
      </template>
    </ScreenHeader>

    <!-- Phone: one column in this order. Wide screen: money on the left, the day's things on the right. -->
    <div class="page-columns">
      <div class="page-col">
        <div v-if="!today && !failed" class="flex flex-col gap-4" aria-hidden="true">
          <div class="skeleton h-4 w-44" />
          <div class="skeleton h-11 w-64" />
          <div class="skeleton h-2 w-full rounded-full" />
          <div class="skeleton h-16 w-full rounded-card" />
        </div>
        <p v-else-if="failed && !today" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

        <template v-else-if="today">
          <UiEmpty v-if="!today.has_accounts" :icon="Wallet" tone="money" :title="$t('today.limit')" :text="$t('today.limitEmpty')">
            <UiButton :to="{ name: 'account-new' }" size="sm">{{ $t('quickAdd.addAccount') }}</UiButton>
          </UiEmpty>
          <template v-else>
            <LimitCard :limit="today.limit" :income-name="today.next_income?.name" />
            <MoneyStreakLine :streaks="today.streaks" />
          </template>

          <WeekSummaryCard v-if="today.week" :week="today.week" />

          <section class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between gap-3">
              <h2 class="section-title">{{ $t('today.goals') }}</h2>
              <RouterLink v-if="today.goals.goals.length" :to="{ name: 'goals' }" class="section-link">{{ $t('today.allGoals') }}</RouterLink>
            </div>
            <div v-if="today.goals.due_uzs > 0" class="card flex items-center gap-3 px-4 py-3" data-panel>
              <div class="flex min-w-0 flex-1 flex-col">
                <span class="text-caption text-ink-2">{{ $t('goals.dueUntil', { date: formatDayMonth(today.goals.period.end, settings.locale) }) }}</span>
                <MoneyAmount :amount="today.goals.due_uzs" animate class="text-lg font-semibold" />
              </div>
              <UiButton size="sm" @click="contributeOpen = true">{{ $t('goals.contribute') }}</UiButton>
            </div>
            <div v-if="topGoals.length" class="group">
              <GoalRow v-for="goal in topGoals" :key="goal.id" :goal="goal" :main="goal.id === today.goals.main_id" />
            </div>
            <UiEmpty v-else :icon="Target" tone="goal" :text="$t('today.goalsEmpty')">
              <UiButton size="sm" variant="secondary" :to="{ name: 'goals' }">{{ $t('goals.new') }}</UiButton>
            </UiEmpty>
          </section>

          <section class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between gap-3">
              <h2 class="section-title">{{ $t('today.advice') }}</h2>
              <RouterLink :to="{ name: 'advice' }" class="section-link">
                {{ today.insights_new > today.insights.length ? $t('today.allAdviceNew', { n: today.insights_new }) : $t('today.allAdvice') }}
              </RouterLink>
            </div>
            <TransitionGroup name="list" tag="div" class="relative flex flex-col gap-2.5">
              <InsightCard v-for="insight in insights" :key="insight.id" :insight="insight" compact />
            </TransitionGroup>
            <UiEmpty v-if="!insights.length" :icon="Lightbulb" tone="money" :text="$t('today.adviceEmpty')" />
            <div class="group">
              <UiRow :to="{ name: 'can-afford' }" :title="$t('advice.canAfford')" :subtitle="$t('advice.canAffordHint')">
                <template #leading>
                  <span class="disc grid size-10 place-items-center rounded-full bg-money text-on-money"><Scale class="size-5" :stroke-width="2" /></span>
                </template>
              </UiRow>
            </div>
          </section>
        </template>
      </div>

      <div class="page-col">
        <TodayPlan />

        <section v-if="habits.loaded" class="flex flex-col gap-3">
          <div class="flex items-baseline justify-between gap-3">
            <h2 class="section-title">
              {{ $t('today.habits') }}
              <span v-if="habits.forToday.length" class="num ml-1 font-medium text-ink-3">{{ habitsDone }}/{{ habits.forToday.length }}</span>
            </h2>
            <RouterLink v-if="habits.active.length" :to="{ name: 'tasks', query: { tab: 'habits' } }" class="section-link">
              {{ $t('habits.all') }}
            </RouterLink>
          </div>
          <div v-if="habits.forToday.length" class="group">
            <HabitRow v-for="habit in habits.forToday" :key="habit.id" :habit="habit" compact @tap="logHabit(habit)" @hold="adjustHabit(habit)" @open="router.push({ name: 'habit', params: { id: habit.id } })" />
          </div>
          <UiEmpty v-else :icon="Repeat" tone="habit" :text="habits.active.length ? $t('habits.emptyToday') : $t('today.habitsEmpty')">
            <UiButton v-if="!habits.active.length" size="sm" variant="secondary" :to="{ name: 'tasks', query: { tab: 'habits', new: '1' } }">{{ $t('habits.add') }}</UiButton>
          </UiEmpty>
        </section>
        <div v-else-if="!thingsFailed" class="skeleton h-36" aria-hidden="true" />

        <section v-if="tasks.loaded" class="flex flex-col gap-3">
          <div class="flex items-baseline justify-between gap-3">
            <h2 class="section-title">{{ $t('today.tasks') }}</h2>
            <RouterLink v-if="laterCount" :to="{ name: 'tasks', query: { tab: 'tasks', view: 'all' } }" class="section-link">
              {{ $t('tasks.later', laterCount) }}
            </RouterLink>
          </div>
          <div class="group relative">
            <TransitionGroup name="row">
              <TaskRow v-for="task in dueTasks" :key="task.id" :task="task" :today="day" hide-today @toggle="toggle(task)" @open="openTask(task)" />
            </TransitionGroup>
            <UiRow :title="$t('tasks.add')" :subtitle="dueTasks.length ? undefined : $t('today.tasksEmpty')" tone="muted" :chevron="false" @click="openTask(null)">
              <template #leading>
                <span class="grid size-[22px] place-items-center text-ink-3"><Plus class="size-5" :stroke-width="2.2" /></span>
              </template>
            </UiRow>
          </div>
        </section>

        <SectionBlock v-if="today?.payments.length" :title="$t('today.payments')">
          <div class="group">
            <PaymentRow
              v-for="payment in today.payments"
              :key="payment.id"
              :payment="payment"
              @open="openPayment(payment)"
              @edit="openPayment(payment)"
            />
          </div>
        </SectionBlock>
      </div>
    </div>

    <TaskSheet v-model:open="taskSheetOpen" :task="editingTask" :draft="{ due_on: day }" />
    <HabitSheet v-model:open="habitSheetOpen" :habit="editingHabit" />
    <HabitValueSheet v-model:open="valueSheetOpen" :habit="valueHabit" @save="saveValue" />
    <ContributeSheet v-model:open="contributeOpen" />
    <PaySheet v-model:open="paySheetOpen" :payment="paying" @edit="(p) => router.push({ name: 'payments', query: { edit: p.id } })" />
  </div>
</template>

<style scoped>
.list-move,
.list-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}
.list-leave-active {
  position: absolute;
  inset-inline: 0;
  transition:
    opacity var(--dur-fast) var(--ease-exit),
    transform var(--dur-fast) var(--ease-exit);
}
.list-enter-from {
  opacity: 0;
  transform: translateY(8px);
}
.list-leave-to {
  opacity: 0;
  transform: translateX(24px);
}

/* A ticked task stays a moment, then slides out; the rows below close the gap. */
.row-move,
.row-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}
.row-leave-active {
  position: absolute;
  inset-inline: 0;
  transition:
    opacity var(--dur-fast) var(--ease-exit),
    transform var(--dur-base) var(--ease-exit);
}
.row-enter-from {
  opacity: 0;
  transform: translateY(-6px);
}
.row-leave-to {
  opacity: 0;
  transform: translateX(32px);
}
</style>
