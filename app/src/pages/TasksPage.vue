<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Archive, CalendarDays, CheckCheck, ListChecks, PauseCircle, Plus, Repeat } from 'lucide-vue-next'
import HabitRow from '@/components/things/HabitRow.vue'
import HabitSheet from '@/components/things/HabitSheet.vue'
import HabitValueSheet from '@/components/things/HabitValueSheet.vue'
import PauseSheet from '@/components/things/PauseSheet.vue'
import PaymentTaskRow from '@/components/things/PaymentTaskRow.vue'
import TaskRow from '@/components/things/TaskRow.vue'
import TaskSheet from '@/components/things/TaskSheet.vue'
import PaySheet from '@/components/plan/PaySheet.vue'
import { habitIcon } from '@/components/things/habitIcons'
import { useTaskToggle, useThingActions } from '@/components/things/useThingActions'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiProgress from '@/components/ui/UiProgress.vue'
import UiRow from '@/components/ui/UiRow.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { showToast } from '@/components/ui/toast'
import { isoDate, shiftDate } from '@/lib/dates'
import { formatDayMonth } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { scheduleLabel } from '@/lib/habits'
import { readJson, writeJson } from '@/lib/storage'
import { groupTasks, groupTitle, withPayments } from '@/lib/tasks'
import { useHabits, type Habit } from '@/stores/habits'
import { useMoney } from '@/stores/money'
import { usePlan, type RecurringPayment } from '@/stores/plan'
import { useSettings } from '@/stores/settings'
import { useTasks, type Task, type TaskInput } from '@/stores/tasks'

type Tab = 'tasks' | 'habits'
type View = 'today' | 'week' | 'all' | 'done'

const TAB_KEY = 'kompas.thingsTab'
const VIEW_KEY = 'kompas.tasksView'
const TABS: Tab[] = ['tasks', 'habits']
const VIEWS: View[] = ['today', 'week', 'all', 'done']

const tasks = useTasks()
const habits = useHabits()
const plan = usePlan()
const money = useMoney()
const settings = useSettings()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { logHabit } = useThingActions()

const tab = ref<Tab>(TABS.includes(readJson<Tab>(TAB_KEY)!) ? readJson<Tab>(TAB_KEY)! : 'tasks')
const view = ref<View>(VIEWS.includes(readJson<View>(VIEW_KEY)!) ? readJson<View>(VIEW_KEY)! : 'today')
const failed = ref(false)
const today = isoDate()

watch(tab, (value) => writeJson(TAB_KEY, value))
watch(view, (value) => {
  writeJson(VIEW_KEY, value)
  if (value === 'done') void tasks.loadDone().catch(() => {})
})

const tabOptions = computed(() => TABS.map((value) => ({ value, label: t(`tasks.tabs.${value}`) })))
const viewOptions = computed(() => VIEWS.map((value) => ({ value, label: t(`tasks.views.${value}`) })))

async function load(): Promise<void> {
  try {
    await Promise.all([tasks.load(), habits.load(), habits.loadPauses(), plan.loadPayments(), money.ensureLoaded(), view.value === 'done' ? tasks.loadDone() : null])
    failed.value = false
  } catch {
    failed.value = true
  }
}

onMounted(load)

// «Все привычки» and «+» on «Сегодня» land here with ?tab=habits&new=1; the query is used once.
watch(
  () => route.query,
  (query) => {
    if (typeof query.tab === 'string' && TABS.includes(query.tab as Tab)) tab.value = query.tab as Tab
    if (typeof query.view === 'string' && VIEWS.includes(query.view as View)) view.value = query.view as View
    if (query.new) openNew()
    if (Object.keys(query).length) void router.replace({ query: {} })
  },
  { immediate: true },
)

// Tasks

const { shown, justDone, toggle } = useTaskToggle(() => tasks.tasks)
// Recurring payments are things to do too: each sits in the section of its due date (D28).
const groups = computed(() =>
  view.value === 'done' ? [] : withPayments(groupTasks(shown.value, view.value, today, justDone.value), plan.payments, view.value, today),
)
const emptyText = computed(() => t(`tasks.empty${view.value.charAt(0).toUpperCase()}${view.value.slice(1)}`))

const quickTitle = ref('')
const quickInput = ref<HTMLInputElement | null>(null)
const adding = ref(false)

async function quickAdd(): Promise<void> {
  const title = quickTitle.value.trim()
  if (!title || adding.value) return
  adding.value = true
  try {
    // Written on «Сегодня» or «Неделя» it is for today; on «Все» it waits without a date.
    await tasks.save({ title, due_on: view.value === 'all' ? null : today })
    quickTitle.value = ''
    haptics.impact('light')
    // Ready for the next one, also after a tap on the button (which then disappears).
    quickInput.value?.focus({ preventScroll: true })
  } catch {
    haptics.notify('error')
    showToast({ message: t('tasks.failed'), tone: 'danger' })
  } finally {
    adding.value = false
  }
}

const taskSheetOpen = ref(false)
const editingTask = ref<Task | null>(null)
const taskDraft = ref<Partial<TaskInput>>({})

const paySheetOpen = ref(false)
const paying = ref<RecurringPayment | null>(null)

function openPayment(payment: RecurringPayment): void {
  paying.value = payment
  paySheetOpen.value = true
}

function editPayment(payment: RecurringPayment): void {
  void router.push({ name: 'payments', query: { edit: payment.id } })
}

function openTask(task: Task | null, draft: Partial<TaskInput> = {}): void {
  editingTask.value = task
  taskDraft.value = draft
  taskSheetOpen.value = true
}

// Habits

const doneToday = computed(() => habits.forToday.filter((h) => h.today.state === 'done').length)
const notToday = computed(() => habits.active.filter((h) => h.today.state === 'off'))

const habitSheetOpen = ref(false)
const editingHabit = ref<Habit | null>(null)
const valueSheetOpen = ref(false)
const valueHabit = ref<Habit | null>(null)

function openHabit(habit: Habit | null): void {
  editingHabit.value = habit
  habitSheetOpen.value = true
}

function showHabit(habit: Habit): void {
  void router.push({ name: 'habit', params: { id: habit.id } })
}

// A pause for all habits (docs/PLAN.md, D37): set or move one ahead in the sheet, end one under way here.
const pauseSheetOpen = ref(false)
const pauseText = computed(() => {
  const active = habits.activePause
  if (active) return t('habits.pause.active', { date: formatDayMonth(active.ends_on, settings.locale) })
  const next = habits.nextPause
  return next ? t('habits.pause.upcoming', { from: formatDayMonth(next.starts_on, settings.locale), to: formatDayMonth(next.ends_on, settings.locale) }) : null
})
const endingPause = ref(false)

/** Ending a pause today: today counts again. One that began today simply goes. */
async function endPause(): Promise<void> {
  const pause = habits.activePause
  if (!pause || endingPause.value) return
  endingPause.value = true
  try {
    if (pause.starts_on >= today) await habits.removePause(pause.id)
    else await habits.savePause({ ends_on: shiftDate(today, -1) }, pause.id)
    haptics.notify('success')
    showToast({ message: t('habits.pause.ended') })
  } catch {
    showToast({ message: t('habits.pause.failed'), tone: 'danger' })
  } finally {
    endingPause.value = false
  }
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

function openNew(): void {
  if (tab.value === 'habits') openHabit(null)
  else openTask(null, view.value === 'all' ? {} : { due_on: today })
}
</script>

<template>
  <div class="page">
    <ScreenHeader :title="$t('tasks.title')">
      <template #trailing>
        <UiIconButton :label="$t('tasks.calendar')" class="mr-1" @click="router.push({ name: 'calendar' })">
          <CalendarDays class="size-[22px]" :stroke-width="1.9" />
        </UiIconButton>
        <UiIconButton tone="filled" :label="tab === 'habits' ? $t('habits.new') : $t('tasks.new')" @click="openNew">
          <Plus class="size-5" :stroke-width="2.2" />
        </UiIconButton>
      </template>
    </ScreenHeader>

    <UiSegmented v-model="tab" :options="tabOptions" :label="$t('tasks.title')" />

    <p v-if="failed" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <Transition name="tab" mode="out-in">
      <!-- Tasks -->
      <div v-if="tab === 'tasks'" key="tasks" class="flex flex-col gap-5">
        <form v-if="view !== 'done'" class="flex min-h-13 items-center gap-3 rounded-control bg-surface-2 pr-1.5 pl-4" @submit.prevent="quickAdd">
          <Plus class="size-5 shrink-0 text-ink-3" :stroke-width="2.2" aria-hidden="true" />
          <input
            ref="quickInput"
            v-model="quickTitle"
            maxlength="200"
            enterkeyhint="done"
            autocomplete="off"
            :placeholder="$t('tasks.addPlaceholder')"
            :aria-label="$t('tasks.addPlaceholder')"
            class="min-w-0 flex-1 bg-transparent py-3.5 outline-none placeholder:text-ink-3"
          />
          <Transition name="pop">
            <UiButton v-if="quickTitle.trim()" type="submit" size="sm" :loading="adding">{{ $t('tasks.addShort') }}</UiButton>
          </Transition>
        </form>

        <UiSegmented v-model="view" :options="viewOptions" :label="$t('tasks.viewLabel')" size="sm" />

        <template v-if="!tasks.loaded && !failed">
          <div class="skeleton h-14" />
          <div class="skeleton h-40" />
        </template>

        <!-- «Готово»: the latest finished tasks, a tap on the check brings one back. -->
        <template v-else-if="view === 'done'">
          <div v-if="tasks.done.length" class="group">
            <TransitionGroup name="row">
              <TaskRow v-for="task in tasks.done" :key="task.id" :task="task" :today="today" @toggle="toggle(task)" @open="openTask(task)" />
            </TransitionGroup>
          </div>
          <UiEmpty v-else :icon="CheckCheck" tone="task" :text="emptyText" />
        </template>

        <template v-else>
          <TransitionGroup name="section" tag="div" class="relative flex flex-col gap-5 empty:hidden">
            <section v-for="group in groups" :key="group.key" class="flex flex-col gap-1.5">
              <h2 class="section-title" :class="{ '!text-danger': group.key === 'overdue' }">
                {{ groupTitle(group.key, today, t, settings.locale) }}
                <span class="num ml-1 font-medium text-ink-3">{{ group.tasks.filter((x) => !x.done_at).length + (group.payments?.length ?? 0) || '' }}</span>
              </h2>
              <TransitionGroup name="row" tag="div" class="group relative">
                <PaymentTaskRow
                  v-for="payment in group.payments"
                  :key="`p${payment.id}`"
                  :payment="payment"
                  :today="today"
                  :hide-today="group.key === 'today'"
                  @open="openPayment(payment)"
                />
                <TaskRow
                  v-for="task in group.tasks"
                  :key="`t${task.id}`"
                  :task="task"
                  :today="today"
                  :hide-today="group.key === 'today'"
                  @toggle="toggle(task)"
                  @open="openTask(tasks.byId.get(task.id) ?? task)"
                />
              </TransitionGroup>
            </section>
          </TransitionGroup>
          <UiEmpty v-if="!groups.length" :icon="ListChecks" tone="task" :text="emptyText" />
        </template>
      </div>

      <!-- Habits -->
      <div v-else key="habits" class="flex flex-col gap-5">
        <template v-if="!habits.loaded && !failed">
          <div class="skeleton h-24" />
          <div class="skeleton h-52" />
        </template>

        <UiEmpty v-else-if="!habits.active.length" :icon="Repeat" tone="habit" :title="$t('habits.title')" :text="$t('habits.empty')">
          <UiButton size="sm" @click="openHabit(null)">{{ $t('habits.add') }}</UiButton>
        </UiEmpty>

        <template v-else>
          <article v-if="habits.activePause" class="card flex items-start gap-3 p-4" data-panel>
            <PauseCircle class="size-6 shrink-0 text-ink-2" :stroke-width="2" />
            <div class="flex min-w-0 flex-1 flex-col items-start gap-0.5">
              <span class="font-semibold">{{ pauseText }}</span>
              <span class="text-caption text-ink-2">{{ $t('habits.pause.activeHint') }}</span>
              <UiButton variant="secondary" size="sm" class="mt-2 !bg-bg" :loading="endingPause" @click="endPause">{{ $t('habits.pause.end') }}</UiButton>
            </div>
          </article>

          <article v-if="habits.forToday.length" class="card flex flex-col gap-3 p-5">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-sm font-medium text-ink-2">{{ $t('habits.summary', { done: doneToday, total: habits.forToday.length }) }}</span>
              <span class="num text-headline font-semibold">{{ Math.round((doneToday / habits.forToday.length) * 100) }}%</span>
            </div>
            <UiProgress :value="doneToday / habits.forToday.length" tone="habit" />
          </article>

          <div class="group">
            <HabitRow
              v-for="habit in habits.forToday"
              :key="habit.id"
              :habit="habit"
              @tap="logHabit(habit)"
              @hold="adjustHabit(habit)"
              @open="showHabit(habit)"
            />
            <UiRow :title="$t('habits.new')" tone="muted" :chevron="false" @click="openHabit(null)">
              <template #leading>
                <span class="grid size-10 place-items-center text-ink-3"><Plus class="size-5" :stroke-width="2.2" /></span>
              </template>
            </UiRow>
          </div>

          <section v-if="notToday.length" class="flex flex-col gap-1.5">
            <h2 class="section-title">{{ $t('habits.notToday') }}</h2>
            <div class="group">
              <HabitRow
                v-for="habit in notToday"
                :key="habit.id"
                :habit="habit"
                class="opacity-70"
                @tap="logHabit(habit)"
                @hold="adjustHabit(habit)"
                @open="showHabit(habit)"
              />
            </div>
          </section>
        </template>

        <div v-if="habits.active.length && !habits.activePause" class="group">
          <UiRow
            :title="habits.nextPause ? pauseText! : $t('habits.pause.row')"
            :subtitle="$t('habits.pause.rowHint')"
            tone="muted"
            @click="pauseSheetOpen = true"
          >
            <template #leading>
              <span class="grid size-10 place-items-center text-ink-3"><PauseCircle class="size-5" :stroke-width="2" /></span>
            </template>
          </UiRow>
        </div>

        <section v-if="habits.archived.length" class="flex flex-col gap-1.5">
          <h2 class="section-title flex items-center gap-1.5"><Archive class="size-3.5" :stroke-width="2.2" />{{ $t('habits.archived') }}</h2>
          <div class="group">
            <UiRow
              v-for="habit in habits.archived"
              :key="habit.id"
              :title="habit.name"
              :subtitle="scheduleLabel(habit.schedule, t)"
              tone="muted"
              @click="openHabit(habit)"
            >
              <template #leading>
                <span class="grid size-10 place-items-center rounded-full bg-surface-2 text-ink-3">
                  <component :is="habitIcon(habit.icon)" class="size-5" :stroke-width="1.9" />
                </span>
              </template>
            </UiRow>
          </div>
        </section>
      </div>
    </Transition>

    <TaskSheet v-model:open="taskSheetOpen" :task="editingTask" :draft="taskDraft" />
    <PaySheet v-model:open="paySheetOpen" :payment="paying" @edit="editPayment" />
    <HabitSheet v-model:open="habitSheetOpen" :habit="editingHabit" />
    <HabitValueSheet v-model:open="valueSheetOpen" :habit="valueHabit" @save="saveValue" />
    <PauseSheet v-model:open="pauseSheetOpen" :pause="habits.nextPause" />
  </div>
</template>

<style scoped>
.tab-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-enter),
    transform var(--dur-spring) var(--ease-spring);
}
.tab-leave-active {
  transition: opacity var(--dur-fast) var(--ease-exit);
}
.tab-enter-from {
  opacity: 0;
  transform: translateY(6px);
}
.tab-leave-to {
  opacity: 0;
}

/* A ticked task slides out to the right; the rows below close the gap. */
.row-move,
.section-move {
  transition: transform var(--dur-spring) var(--ease-spring);
}
.row-enter-active,
.section-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}
.row-leave-active,
.section-leave-active {
  position: absolute;
  inset-inline: 0;
  transition:
    opacity var(--dur-fast) var(--ease-exit),
    transform var(--dur-base) var(--ease-exit);
}
.row-enter-from,
.section-enter-from {
  opacity: 0;
  transform: translateY(-6px);
}
.row-leave-to {
  opacity: 0;
  transform: translateX(32px);
}
.section-leave-to {
  opacity: 0;
}

.pop-enter-active {
  transition:
    opacity var(--dur-fast) var(--ease-standard),
    transform var(--dur-pop) var(--ease-pop);
}
.pop-leave-active {
  transition: opacity 90ms var(--ease-exit);
}
.pop-enter-from {
  opacity: 0;
  transform: scale(0.85);
}
.pop-leave-to {
  opacity: 0;
}
</style>
