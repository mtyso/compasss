<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import CalendarHead from '@/components/calendar/CalendarHead.vue'
import DayView from '@/components/calendar/DayView.vue'
import EventSheet from '@/components/calendar/EventSheet.vue'
import LayersList from '@/components/calendar/LayersList.vue'
import LayersSheet from '@/components/calendar/LayersSheet.vue'
import ListView from '@/components/calendar/ListView.vue'
import MiniMonth from '@/components/calendar/MiniMonth.vue'
import MonthPickerSheet from '@/components/calendar/MonthPickerSheet.vue'
import MonthView from '@/components/calendar/MonthView.vue'
import RoutineSheet from '@/components/calendar/RoutineSheet.vue'
import ScopeSheet from '@/components/calendar/ScopeSheet.vue'
import WeekView from '@/components/calendar/WeekView.vue'
import { scrolledAlready } from '@/components/calendar/timeline'
import { useCalendarOpen } from '@/components/calendar/useCalendarOpen'
import PaySheet from '@/components/plan/PaySheet.vue'
import HabitValueSheet from '@/components/things/HabitValueSheet.vue'
import TaskSheet from '@/components/things/TaskSheet.vue'
import { hasOverlay } from '@/components/ui/overlays'
import { defaultView, layerOf, newEventDay, nextHourDraft, periodTitle, rangeFor, shiftAnchor, toMinutes, weekDays } from '@/lib/calendar'
import { isoDate, isoTime, shiftDate } from '@/lib/dates'
import { prefersReducedMotion } from '@/lib/motion'
import { readJson, writeJson } from '@/lib/storage'
import { useCalendar, type CalendarView } from '@/stores/calendar'
import { useHabits } from '@/stores/habits'
import { useMoney } from '@/stores/money'
import { usePlan } from '@/stores/plan'
import { useSettings } from '@/stores/settings'
import { useTasks } from '@/stores/tasks'

/**
 * The calendar (docs/PLAN.md): day, week, month and list over one feed of events, tasks, payments,
 * paydays, goal deadlines, money lent and habits, with the routine behind the day and the week.
 * The view and the date live in the query; the view is also remembered on the device.
 */
const calendar = useCalendar()
const tasks = useTasks()
const habits = useHabits()
const plan = usePlan()
const money = useMoney()
const settings = useSettings()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const VIEW_KEY = 'kompas.calendarView'
const VIEWS: CalendarView[] = ['day', 'week', 'month', 'list']

function isView(value: unknown): value is CalendarView {
  return typeof value === 'string' && VIEWS.includes(value as CalendarView)
}

function isDay(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value))
}

/* ---------- Screen width and the clock ---------- */

const wideQuery = window.matchMedia('(min-width: 48rem)')
const panelQuery = window.matchMedia('(min-width: 80rem)')
const wide = ref(wideQuery.matches)
const panel = ref(panelQuery.matches)
const onWide = (event: MediaQueryListEvent) => (wide.value = event.matches)
const onPanel = (event: MediaQueryListEvent) => (panel.value = event.matches)
wideQuery.addEventListener('change', onWide)
panelQuery.addEventListener('change', onPanel)

const clock = ref(Date.now())
const tick = () => (clock.value = Date.now())
const timer = window.setInterval(tick, 30_000)
const onVisible = () => document.visibilityState === 'visible' && tick()
document.addEventListener('visibilitychange', onVisible)

const today = computed(() => isoDate(new Date(clock.value)))
const nowMin = computed(() => toMinutes(isoTime(new Date(clock.value))))

/* ---------- View and date, in the query ---------- */

const stored = readJson<unknown>(VIEW_KEY)
const view = ref<CalendarView>(isView(route.query.view) ? route.query.view : isView(stored) ? stored : defaultView(wide.value))
const anchor = ref(isDay(route.query.date) ? route.query.date : today.value)

watch(view, (value) => writeJson(VIEW_KEY, value))

// The date stays out of the query while it is today, so a link back here keeps meaning «today».
watch(
  [view, anchor],
  () => {
    if (route.name !== 'calendar') return
    const date = anchor.value === today.value ? undefined : anchor.value
    if (route.query.view === view.value && route.query.date === date) return
    void router.replace({ query: { view: view.value, ...(date ? { date } : {}) } })
  },
  { immediate: true },
)

// A link to the calendar while it is open («Календарь» on «Сегодня» in the sidebar layout).
watch(
  () => route.query,
  (query) => {
    if (route.name !== 'calendar') return
    if (isView(query.view) && query.view !== view.value) view.value = query.view
    if (isDay(query.date) && query.date !== anchor.value) anchor.value = query.date
  },
)

const range = computed(() => rangeFor(view.value, anchor.value))
const week = computed(() => weekDays(anchor.value))
const title = computed(() => periodTitle(view.value, anchor.value, today.value, settings.locale, t))

// The range on screen, then the periods on either side, so that paging shows them at once.
watch(
  () => `${range.value.from}:${range.value.to}`,
  async () => {
    const { from, to } = range.value
    await calendar.show(from, to)
    for (const delta of [-1, 1]) {
      const near = rangeFor(view.value, shiftAnchor(view.value, anchor.value, delta))
      void calendar.prefetch(near.from, near.to)
    }
  },
  { immediate: true },
)

function go(delta: number): void {
  anchor.value = shiftAnchor(view.value, anchor.value, delta)
}

function goToday(): void {
  anchor.value = today.value
}

function openDay(day: string): void {
  anchor.value = day
  view.value = 'day'
}

/* ---------- What the views show ---------- */

const actions = useCalendarOpen(
  () => calendar.feed,
  () => today.value,
)

const items = computed(() => actions.patched(calendar.feed?.items ?? []).filter((item) => calendar.layers.has(layerOf(item))))
const routine = computed(() => (calendar.layers.has('routine') ? (calendar.feed?.routine ?? []) : []))
/** The feed on screen covers the range (not the period shown before, still there while the new one loads). */
const ready = computed(() => {
  const feed = calendar.feed
  return !!feed && feed.from <= range.value.from && range.value.to <= feed.to
})
const skeleton = computed(() => !calendar.feed && !calendar.failed)

/** Days that have something, for the dots of the mini month: every feed this visit has loaded. */
const marked = computed(() => {
  const days = new Set<string>()
  for (const feed of [calendar.feed, ...calendar.feeds.values()]) {
    for (const item of feed?.items ?? []) {
      if (item.type === 'habit' || !calendar.layers.has(layerOf(item))) continue
      if (item.type !== 'event') days.add(item.date)
      else for (let day = item.date; day <= item.end_date; day = shiftDate(day, 1)) days.add(day)
    }
  }
  return days
})

const miniMonth = ref(anchor.value.slice(0, 7))
watch(anchor, (day) => (miniMonth.value = day.slice(0, 7)))

const pickerOpen = ref(false)
const layersOpen = ref(false)

function add(): void {
  actions.newEvent(nextHourDraft(newEventDay(view.value, anchor.value, today.value), today.value, nowMin.value))
}

/* ---------- Loading and refreshing ---------- */

onMounted(() => {
  // What the sheets need; the feed itself loads above.
  void Promise.all([tasks.ensureLoaded(), habits.ensureLoaded(), plan.loadPayments(), money.ensureLoaded(), calendar.ensureRoutine()]).catch(() => {})
})

watch(
  () => money.revision,
  () => void calendar.refresh(),
)
// Tasks changed elsewhere (a sheet, a tick): their dates and ticks show in the feed. Not the first load.
watch(
  () => tasks.loaded && tasks.tasks.map((task) => `${task.id}:${task.due_on}:${task.done_at}`).join(),
  (now, before) => {
    if (now !== false && before !== false) void calendar.refresh()
  },
)
for (const sheet of [actions.taskSheet, actions.paySheet, actions.eventSheet, actions.routineSheet, actions.valueSheet]) {
  watch(
    () => sheet.open,
    (open) => {
      if (!open) void calendar.refresh()
    },
  )
}

/* ---------- Where the page opens ---------- */

const root = ref<HTMLElement | null>(null)
const timed = ref<InstanceType<typeof DayView> | InstanceType<typeof WeekView> | null>(null)
/** The day or the week was opened anew: once the feed covers it, its hours open at the first thing (or now). */
let startWanted = true

function scrollToStartIfWanted(): void {
  if (!startWanted || !ready.value || skeleton.value) return
  startWanted = false
  // After the view on screen is the one wanted and laid out.
  void nextTick(() => requestAnimationFrame(() => timed.value?.scrollToStart()))
}

/** The month and the list start at their top. */
function scrollToTop(): void {
  void nextTick(() => {
    const scroller = root.value?.closest<HTMLElement>('[data-scroller]')
    if (scroller) scroller.scrollTop = 0
  })
}

onMounted(() => {
  // Back on the calendar, AppShell has already put the page where it was (it runs before this hook).
  if (scrolledAlready(root.value)) startWanted = false
  scrollToStartIfWanted()
})
// Another view or day opens at its own start; another week keeps the hours where they were.
watch([view, anchor], ([value], [before]) => {
  if (value === 'month' || value === 'list') return scrollToTop()
  if (value === 'week' && before === 'week') return
  startWanted = true
  scrollToStartIfWanted()
})
watch([ready, skeleton], scrollToStartIfWanted)

/* ---------- The sticky head's height, for the sticky parts under it ---------- */

const head = ref<InstanceType<typeof CalendarHead> | null>(null)
const headHeight = ref(0)
let headObserver: ResizeObserver | null = null

/** Where the head ends when stuck, from the top of the scroller's padding (it sits 1.5rem higher on a wide screen). */
function measureHead(): void {
  const element = head.value?.$el as HTMLElement | undefined
  if (element) headHeight.value = element.offsetHeight + (parseFloat(getComputedStyle(element).top) || 0)
}

onMounted(() => {
  measureHead()
  const element = head.value?.$el as HTMLElement | undefined
  if (element && typeof ResizeObserver !== 'undefined') {
    headObserver = new ResizeObserver(measureHead)
    headObserver.observe(element)
  }
})
watch(wide, () => nextTick(measureHead))

/* ---------- Swipe (touch) between periods ---------- */

const pane = ref<HTMLElement | null>(null)
let swipe: { id: number; x: number; y: number; dx: number; active: boolean } | null = null
let turning = false

function onSwipeStart(event: PointerEvent): void {
  if (event.pointerType !== 'touch' || !event.isPrimary || view.value === 'list' || turning) return
  swipe = { id: event.pointerId, x: event.clientX, y: event.clientY, dx: 0, active: false }
}

function onSwipeMove(event: PointerEvent): void {
  if (!swipe || event.pointerId !== swipe.id) return
  const dx = event.clientX - swipe.x
  const dy = event.clientY - swipe.y
  if (!swipe.active) {
    if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return
    // Mostly vertical: the page scrolls (touch-action: pan-y), the swipe is off.
    if (Math.abs(dx) < Math.abs(dy) * 1.2) {
      swipe = null
      return
    }
    swipe.active = true
    if (pane.value && !prefersReducedMotion()) pane.value.style.willChange = 'transform, opacity'
  }
  swipe.dx = dx
  const element = pane.value
  if (!element || prefersReducedMotion()) return
  element.style.transform = `translateX(${dx}px)`
  element.style.opacity = String(1 - Math.min(Math.abs(dx) / element.offsetWidth, 1) * 0.5)
}

function onSwipeEnd(event: PointerEvent): void {
  if (!swipe || event.pointerId !== swipe.id) return
  const { dx, active } = swipe
  swipe = null
  if (!active) return
  if (Math.abs(dx) >= 60) void turn(dx < 0 ? 1 : -1, dx)
  else settleBack(dx)
}

function onSwipeCancel(event: PointerEvent): void {
  if (!swipe || event.pointerId !== swipe.id) return
  if (swipe.active) settleBack(swipe.dx)
  swipe = null
}

function clearPane(element: HTMLElement): void {
  element.getAnimations().forEach((animation) => animation.cancel())
  element.style.transform = ''
  element.style.opacity = ''
  element.style.willChange = ''
}

/** Not far enough: the grid springs back. */
function settleBack(dx: number): void {
  const element = pane.value
  if (!element || prefersReducedMotion()) return
  const from = { transform: `translateX(${dx}px)`, opacity: element.style.opacity || '1' }
  clearPane(element)
  element.animate([from, { transform: 'none', opacity: 1 }], { duration: 320, easing: 'cubic-bezier(0.2, 0.9, 0.1, 1)' })
}

/** The period before (-1) or after (+1): the grid slides out the way the finger went, the new one in. */
async function turn(delta: number, dx: number): Promise<void> {
  const element = pane.value
  turning = true
  try {
    if (!element || prefersReducedMotion()) {
      await element?.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 90, fill: 'forwards' }).finished.catch(() => {})
      go(delta)
      await nextTick()
      if (element) {
        clearPane(element)
        element.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 160 })
      }
      return
    }
    const from = { transform: `translateX(${dx}px)`, opacity: element.style.opacity || '1' }
    const out = { transform: `translateX(${-delta * element.offsetWidth * 0.35}px)`, opacity: 0 }
    await element.animate([from, out], { duration: 140, easing: 'cubic-bezier(0.3, 0, 0.8, 0.15)', fill: 'forwards' }).finished.catch(() => {})
    go(delta)
    await nextTick()
    clearPane(element)
    element.style.willChange = 'transform, opacity'
    await element
      .animate([{ transform: `translateX(${delta * 32}px)`, opacity: 0 }, { transform: 'none', opacity: 1 }], {
        duration: 280,
        easing: 'cubic-bezier(0.05, 0.7, 0.1, 1)',
      })
      .finished.catch(() => {})
    element.style.willChange = ''
  } finally {
    turning = false
  }
}

/* ---------- Keys (wide screens) ---------- */

const VIEW_KEYS: Record<string, CalendarView> = { KeyD: 'day', KeyW: 'week', KeyM: 'month', KeyA: 'list' }

function onKey(event: KeyboardEvent): void {
  if (!wide.value || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || hasOverlay()) return
  if ((event.target as HTMLElement | null)?.closest?.('input, textarea, select, [contenteditable="true"]')) return
  // By the key's place, not its letter: the same keys work on a Russian layout.
  const code = event.code
  if (code === 'ArrowLeft' || code === 'KeyK') go(-1)
  else if (code === 'ArrowRight' || code === 'KeyJ') go(1)
  else if (code === 'KeyT') goToday()
  else if (code === 'KeyN') add()
  else if (VIEW_KEYS[code]) view.value = VIEW_KEYS[code]
  else return
  event.preventDefault()
}

window.addEventListener('keydown', onKey)

onBeforeUnmount(() => {
  wideQuery.removeEventListener('change', onWide)
  panelQuery.removeEventListener('change', onPanel)
  window.clearInterval(timer)
  document.removeEventListener('visibilitychange', onVisible)
  window.removeEventListener('keydown', onKey)
  headObserver?.disconnect()
})
</script>

<template>
  <div ref="root" class="calendar -mx-4 md:mx-0" :style="{ '--cal-head': `${headHeight}px` }">
    <CalendarHead
      ref="head"
      v-model:view="view"
      :title="title"
      :today="today"
      :wide="wide"
      :panel="panel"
      :line="view === 'list'"
      :busy="calendar.loading && !!calendar.feed"
      @pick="pickerOpen = true"
      @today="goToday"
      @prev="go(-1)"
      @next="go(1)"
      @layers="layersOpen = true"
      @add="add"
    />

    <div class="md:flex md:items-start md:gap-8">
      <!-- Wide screens: the mini month and the layers stay beside the grid. -->
      <aside
        v-if="panel"
        class="no-scrollbar sticky flex w-60 shrink-0 flex-col gap-7 overflow-y-auto pt-3 pb-6"
        :style="{ top: 'var(--cal-head, 0px)', maxHeight: 'calc(100dvh - var(--cal-head, 0px) - 3rem)' }"
      >
        <MiniMonth v-model:month="miniMonth" :selected="anchor" :today="today" :marked="marked" compact @pick="anchor = $event" />
        <section class="flex flex-col gap-1.5">
          <h2 class="field-label px-2">{{ $t('calendar.layers') }}</h2>
          <LayersList dense />
        </section>
      </aside>

      <div class="min-w-0 flex-1">
        <p v-if="calendar.failed" role="alert" class="mx-4 mt-3 mb-1 rounded-card bg-danger-soft px-4 py-3 text-sm text-danger md:mx-0">
          {{ $t('calendar.loadFailed') }}
        </p>

        <div
          class="overflow-x-clip"
          :class="{ 'touch-pan-y': view !== 'list' }"
          @pointerdown="onSwipeStart"
          @pointermove="onSwipeMove"
          @pointerup="onSwipeEnd"
          @pointercancel="onSwipeCancel"
        >
          <div ref="pane">
            <DayView
              v-if="view === 'day'"
              ref="timed"
              :day="anchor"
              :today="today"
              :now-min="nowMin"
              :items="items"
              :routine="routine"
              :layers="calendar.layers"
              :wide="wide"
              :columns="panel ? 4 : 3"
              :skeleton="skeleton"
              @open="actions.openItem"
              @routine="actions.openRoutine"
              @create="actions.newEvent"
              @toggle-task="actions.toggleTask"
              @habit-tap="actions.tapHabit"
              @habit-hold="actions.holdHabit"
              @drop="actions.dropEvent"
            />
            <WeekView
              v-else-if="view === 'week'"
              ref="timed"
              :days="week"
              :today="today"
              :now-min="nowMin"
              :items="items"
              :routine="routine"
              :layers="calendar.layers"
              :wide="wide"
              :skeleton="skeleton"
              @open="actions.openItem"
              @create="actions.newEvent"
              @pick-day="openDay"
              @drop="actions.dropEvent"
            />
            <MonthView
              v-else-if="view === 'month'"
              :anchor="anchor"
              :today="today"
              :items="items"
              :layers="calendar.layers"
              :wide="wide"
              :skeleton="skeleton"
              @open="actions.openItem"
              @pick-day="openDay"
            />
            <ListView v-else :from="anchor" :today="today" :items="items" :layers="calendar.layers" :skeleton="skeleton" @open="actions.openItem" />
          </div>
        </div>
      </div>
    </div>

    <MonthPickerSheet v-model:open="pickerOpen" :selected="anchor" :today="today" :marked="marked" @pick="anchor = $event" />
    <LayersSheet v-model:open="layersOpen" />

    <EventSheet
      v-model:open="actions.eventSheet.open"
      :event="actions.eventSheet.event"
      :occurrence="actions.eventSheet.occurrence"
      :draft="actions.eventSheet.draft"
    />
    <ScopeSheet v-model:open="actions.scopeSheet.open" mode="edit" :allow-following="actions.scopeSheet.allowFollowing" @pick="actions.pickScope" />
    <RoutineSheet v-model:open="actions.routineSheet.open" :block="actions.routineSheet.block" />
    <TaskSheet v-model:open="actions.taskSheet.open" :task="actions.taskSheet.task" />
    <PaySheet v-model:open="actions.paySheet.open" :payment="actions.paySheet.payment" @edit="actions.editPayment" />
    <HabitValueSheet
      v-model:open="actions.valueSheet.open"
      :habit="actions.valueSheet.habit"
      :date="actions.valueSheet.item?.date"
      @save="actions.saveValue"
    />
  </div>
</template>
