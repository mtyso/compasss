<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Lightbulb, Plus, Repeat, Scale, Wallet } from 'lucide-vue-next'
import InsightCard from '@/components/advisor/InsightCard.vue'
import SectionBlock from '@/components/SectionBlock.vue'
import HabitRow from '@/components/things/HabitRow.vue'
import HabitSheet from '@/components/things/HabitSheet.vue'
import HabitValueSheet from '@/components/things/HabitValueSheet.vue'
import TaskRow from '@/components/things/TaskRow.vue'
import TaskSheet from '@/components/things/TaskSheet.vue'
import { useTaskToggle, useThingActions } from '@/components/things/useThingActions'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiRow from '@/components/ui/UiRow.vue'
import LimitCard from '@/components/plan/LimitCard.vue'
import PaymentRow from '@/components/plan/PaymentRow.vue'
import { isoDate } from '@/lib/dates'
import { formatDayHeading } from '@/lib/format'
import { groupTasks } from '@/lib/tasks'
import { useHabits, type Habit } from '@/stores/habits'
import { useMoney } from '@/stores/money'
import { usePlan } from '@/stores/plan'
import { useSettings } from '@/stores/settings'
import { useTasks, type Task } from '@/stores/tasks'

const settings = useSettings()
const money = useMoney()
const plan = usePlan()
const router = useRouter()
const failed = ref(false)

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
  <div class="page">
    <ScreenHeader :title="$t('today.title')" :overline="heading" />

    <div v-if="!today && !failed" class="flex flex-col gap-6" aria-hidden="true">
      <div class="skeleton h-52 !rounded-hero" />
      <div class="skeleton h-28" />
    </div>
    <p v-else-if="failed && !today" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="today">
      <UiEmpty v-if="!today.has_accounts" :icon="Wallet" tone="money" :title="$t('today.limit')" :text="$t('today.limitEmpty')">
        <UiButton :to="{ name: 'account-new' }" size="sm">{{ $t('quickAdd.addAccount') }}</UiButton>
      </UiEmpty>
      <LimitCard v-else :limit="today.limit" :income-name="today.next_income?.name" />

      <section class="flex flex-col gap-2.5">
        <div class="flex items-baseline justify-between gap-3">
          <h2 class="section-title">{{ $t('today.advice') }}</h2>
          <RouterLink :to="{ name: 'advice' }" class="press px-1 text-caption font-medium text-ink-2">
            {{ today.insights_new > today.insights.length ? $t('today.allAdviceNew', { n: today.insights_new }) : $t('today.allAdvice') }}
          </RouterLink>
        </div>
        <TransitionGroup name="list" tag="div" class="relative flex flex-col gap-3">
          <InsightCard v-for="insight in today.insights" :key="insight.id" :insight="insight" compact />
        </TransitionGroup>
        <UiEmpty v-if="!today.insights.length" :icon="Lightbulb" tone="money" :text="$t('today.adviceEmpty')" />
        <div class="group">
          <UiRow :to="{ name: 'can-afford' }" :title="$t('advice.canAfford')" :subtitle="$t('advice.canAffordHint')">
            <template #leading>
              <span class="grid size-9 place-items-center rounded-xl bg-money-soft text-money"><Scale class="size-5" :stroke-width="2" /></span>
            </template>
          </UiRow>
        </div>
      </section>
    </template>

    <section v-if="habits.loaded" class="flex flex-col gap-2.5">
      <div class="flex items-baseline justify-between gap-3">
        <h2 class="section-title">
          {{ $t('today.habits') }}
          <span v-if="habits.forToday.length" class="num ml-1 font-normal text-ink-3">{{ habitsDone }}/{{ habits.forToday.length }}</span>
        </h2>
        <RouterLink v-if="habits.active.length" :to="{ name: 'tasks', query: { tab: 'habits' } }" class="press px-1 text-caption font-medium text-ink-2">
          {{ $t('habits.all') }}
        </RouterLink>
      </div>
      <div v-if="habits.forToday.length" class="group">
        <HabitRow v-for="habit in habits.forToday" :key="habit.id" :habit="habit" compact @tap="logHabit(habit)" @hold="adjustHabit(habit)" @open="openHabit(habit)" />
      </div>
      <UiEmpty v-else :icon="Repeat" tone="habit" :text="habits.active.length ? $t('habits.emptyToday') : $t('today.habitsEmpty')">
        <UiButton v-if="!habits.active.length" size="sm" variant="secondary" :to="{ name: 'tasks', query: { tab: 'habits', new: '1' } }">{{ $t('habits.add') }}</UiButton>
      </UiEmpty>
    </section>
    <div v-else-if="!thingsFailed" class="skeleton h-36" aria-hidden="true" />

    <section v-if="tasks.loaded" class="flex flex-col gap-2.5">
      <div class="flex items-baseline justify-between gap-3">
        <h2 class="section-title">{{ $t('today.tasks') }}</h2>
        <RouterLink v-if="laterCount" :to="{ name: 'tasks', query: { tab: 'tasks', view: 'all' } }" class="press px-1 text-caption font-medium text-ink-2">
          {{ $t('tasks.later', laterCount) }}
        </RouterLink>
      </div>
      <div class="group relative">
        <TransitionGroup name="row">
          <TaskRow v-for="task in dueTasks" :key="task.id" :task="task" :today="day" hide-today @toggle="toggle(task)" @open="openTask(task)" />
        </TransitionGroup>
        <UiRow :title="$t('tasks.add')" :subtitle="dueTasks.length ? undefined : $t('today.tasksEmpty')" tone="muted" :chevron="false" @click="openTask(null)">
          <template #leading>
            <span class="grid size-9 place-items-center rounded-xl border border-dashed border-ink/20"><Plus class="size-[18px]" /></span>
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
          @open="router.push({ name: 'payments' })"
          @edit="router.push({ name: 'payments' })"
        />
      </div>
    </SectionBlock>

    <TaskSheet v-model:open="taskSheetOpen" :task="editingTask" :draft="{ due_on: day }" />
    <HabitSheet v-model:open="habitSheetOpen" :habit="editingHabit" />
    <HabitValueSheet v-model:open="valueSheetOpen" :habit="valueHabit" @save="saveValue" />
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
