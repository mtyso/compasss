<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronUp } from 'lucide-vue-next'
import HabitButton from '@/components/things/HabitButton.vue'
import { habitIcon } from '@/components/things/habitIcons'
import {
  allDayItems,
  cascade,
  daySegments,
  DAY_MINUTES,
  eventDraft,
  fromMinutes,
  habitOnDay,
  habitsFor,
  layoutColumns,
  roundSlot,
  routineSegments,
  startMinute,
  toMinutes,
  type Columns,
  type Segment,
} from '@/lib/calendar'
import type { CalendarItem, EventInput, EventItem, HabitItem, Layer, RoutineOccurrence, TaskItem } from '@/stores/calendar'
import { useHabits, type Habit } from '@/stores/habits'
import CalendarChip from './CalendarChip.vue'
import ThingBlock from './ThingBlock.vue'
import TimeGutter from './TimeGutter.vue'
import { routineColor, routineLook } from './routineLook'
import { hourLines, scrollTimeline } from './timeline'
import { useCalendarDrag, type DragDrop } from './useCalendarDrag'

/**
 * One day: the all-day area (chips, and the day's habits behind one «Привычки 2/5» chip) stuck
 * under the calendar's head, then the hours. With the routine layer on, the routine runs down a
 * lane on the left and the events, tasks and habits with a time in the lane on the right.
 */
const props = defineProps<{
  day: string
  today: string
  nowMin: number
  /** On the shown layers, with local changes laid over. */
  items: CalendarItem[]
  /** Empty when the routine layer is off. */
  routine: RoutineOccurrence[]
  layers: ReadonlySet<Layer>
  /** A wide screen: shorter hours, mouse drags, every all-day chip shown. */
  wide: boolean
  /** Chips per row of the all-day area on a wide screen. */
  columns?: number
  /** Nothing loaded yet. */
  skeleton: boolean
}>()
const emit = defineEmits<{
  open: [item: CalendarItem]
  routine: [occurrence: RoutineOccurrence]
  create: [draft: EventInput]
  toggleTask: [item: TaskItem]
  habitTap: [item: HabitItem]
  habitHold: [item: HabitItem]
  drop: [drop: DragDrop]
}>()

const { t } = useI18n()
const habits = useHabits()

const hourHeight = computed(() => (props.wide ? 52 : 56))
const ppm = computed(() => hourHeight.value / 60)
const isToday = computed(() => props.day === props.today)
const routineOn = computed(() => props.layers.has('routine'))

/** The habit as it stood on this day, from the habits store (never a partial habit). */
function perDay(item: HabitItem): Habit | null {
  const habit = habits.habits.find((h) => h.id === item.habit_id)
  return habit ? habitOnDay(habit, item) : null
}

/* ---------- Routine lane ---------- */

const routineSegs = computed(() => cascade(routineSegments(props.routine, props.day)))
const dayHabits = computed(() => (props.layers.has('habits') ? habitsFor(props.items, props.day) : []))

function heightOf(segment: { startMin: number; endMin: number }): number {
  return (segment.endMin - segment.startMin) * ppm.value - 1
}

/** Routine blocks that carry their habit's check: tall enough, and the block that starts this day. */
const blockHabits = computed(() => {
  const map = new Map<string, HabitItem>()
  const used = new Set<number>()
  for (const segment of routineSegs.value) {
    const id = segment.item.habit_id
    if (id === null || used.has(id) || segment.item.date !== props.day || heightOf(segment) < 44) continue
    const item = dayHabits.value.find((h) => h.habit_id === id)
    if (!item) continue
    map.set(segment.key, item)
    used.add(id)
  }
  return map
})

function routineStyle(segment: Segment<RoutineOccurrence> & { level: number }): Record<string, string> {
  const color = routineColor(segment.item.kind)
  const now = isToday.value && segment.startMin <= props.nowMin && props.nowMin < segment.endMin
  return {
    top: `${segment.startMin * ppm.value}px`,
    height: `${heightOf(segment)}px`,
    left: `${segment.level * 0.5}rem`,
    '--rc': color,
    backgroundColor: `color-mix(in srgb, ${color} ${now ? 22 : 14}%, var(--c-bg))`,
    ...(segment.level ? { boxShadow: '0 0 0 1px var(--c-bg)' } : {}),
  }
}

/* ---------- Things lane ---------- */

const onBlocks = computed(() => new Set([...blockHabits.value.values()].map((h) => h.key)))

/** Events and tasks with a time; habits tied to a routine block only while the routine is hidden. */
const laneItems = computed(() =>
  props.items.filter((item) => (item.type === 'habit' ? !routineOn.value && !!item.start : item.type === 'event' || item.type === 'task')),
)
const placed = computed(() => layoutColumns(daySegments(laneItems.value, props.day), 22 / ppm.value))

const lane = ref<HTMLElement | null>(null)
const laneWidth = ref(0)
let observer: ResizeObserver | null = null
onMounted(() => {
  if (typeof ResizeObserver === 'undefined' || !lane.value) return
  observer = new ResizeObserver(([entry]) => (laneWidth.value = entry?.contentRect.width ?? 0))
  observer.observe(lane.value)
})
onBeforeUnmount(() => observer?.disconnect())

type Placed = Segment<CalendarItem> & Columns

/** The shortest task block that holds the 22px circle of its check. */
const TASK_CHECK_MIN = 24

function blockHeight(segment: Placed): number {
  return Math.max(22, heightOf(segment))
}

function blockStyle(segment: Placed): Record<string, string> {
  const dragging = drag.state.value
  const moving = dragging && dragging.mode !== 'create' && dragging.key === segment.key ? dragging : null
  const extra = moving?.mode === 'resize' ? moving.minutes * ppm.value : 0
  return {
    top: `${segment.startMin * ppm.value}px`,
    height: `${blockHeight(segment) + extra}px`,
    left: `${(segment.col / segment.cols) * 100}%`,
    width: `calc(${((segment.span + 1) / segment.cols) * 100}% - 2px)`,
    ...(moving?.mode === 'move' ? { transform: `translateY(${moving.dy}px)` } : {}),
  }
}

/**
 * Nested controls only where they fit a finger, and never on a projected task: 96px wide, and 44px
 * tall for a habit's button. A task's check keeps its own 44px target past the block's edges (like in
 * a task row), so a task at its usual half hour only needs room for the circle.
 */
function hasControls(segment: Placed): boolean {
  const item = segment.item
  if (item.type === 'event' || (item.type === 'task' && item.projected)) return false
  const tall = item.type === 'task' ? TASK_CHECK_MIN : 44
  return blockHeight(segment) >= tall && (laneWidth.value * (segment.span + 1)) / segment.cols >= 96
}

function asThing(item: CalendarItem): EventItem | TaskItem | HabitItem {
  return item as EventItem | TaskItem | HabitItem
}

/** The time a dragged block shows while it moves. */
function dragTime(segment: Placed): string | null {
  const s = drag.state.value
  if (!s || s.mode === 'create' || s.key !== segment.key || segment.item.type !== 'event') return null
  const item = segment.item
  if (!item.start || !item.end) return null
  const shift = (time: string) => fromMinutes(toMinutes(time) + s.minutes)
  return `${s.mode === 'move' ? shift(item.start) : item.start}–${shift(item.end)}`
}

const drag = useCalendarDrag({
  enabled: () => props.wide,
  pxPerMin: () => ppm.value,
  columns: () => [lane.value],
  create: (_col, startMin, endMin) => emit('create', eventDraft(props.day, startMin, endMin)),
  drop: (drop) => emit('drop', drop),
})

function grab(event: PointerEvent, segment: Placed, mode: 'move' | 'resize'): void {
  if (segment.item.type === 'event') drag.fromBlock(event, segment.item, segment, 0, mode)
}

/** A tap on empty space: an hour-long event from the quarter tapped. */
function onLaneClick(event: MouseEvent): void {
  if ((event.target as Element).closest('[data-block]') || !lane.value) return
  const minute = (event.clientY - lane.value.getBoundingClientRect().top) / ppm.value
  const start = Math.min(roundSlot(Math.max(0, minute)), DAY_MINUTES - 15)
  emit('create', eventDraft(props.day, start, Math.min(start + 60, DAY_MINUTES)))
}

const ghost = computed(() => {
  const s = drag.state.value
  if (s?.mode !== 'create') return null
  return { top: `${s.startMin * ppm.value}px`, height: `${(s.endMin - s.startMin) * ppm.value - 1}px`, text: `${fromMinutes(s.startMin)}–${fromMinutes(s.endMin)}` }
})

/* ---------- All-day area ---------- */

const chips = computed(() => allDayItems(props.items, props.day, props.layers))
/** Habits in the chip: the ones not shown on a routine block or in the things lane. */
const chipHabits = computed(() => dayHabits.value.filter((h) => !onBlocks.value.has(h.key) && !(!routineOn.value && h.start)))
const habitsDone = computed(() => chipHabits.value.filter((h) => h.state === 'done').length)

const expanded = ref(false)
const habitsOpen = ref(false)
watch(
  () => props.day,
  () => {
    expanded.value = false
    habitsOpen.value = false
  },
)

/** A phone shows two rows of two chips; what does not fit folds into «ещё N». */
const shownChips = computed(() => {
  const cells = chips.value.length + (chipHabits.value.length ? 1 : 0)
  if (props.wide || expanded.value || cells <= 4) return chips.value
  return chips.value.slice(0, 3 - (chipHabits.value.length ? 1 : 0))
})
const hiddenChips = computed(() => chips.value.length - shownChips.value.length)
const showAllDay = computed(() => props.wide || chips.value.length > 0 || chipHabits.value.length > 0)

function onAllDayClick(event: MouseEvent): void {
  if (event.target !== event.currentTarget) return
  emit('create', { starts_on: props.day, ends_on: props.day, start_time: null, end_time: null })
}

function habitLabel(habit: Habit): string {
  return habit.type === 'check' ? t(habit.today.value > 0 ? 'habits.unmark' : 'habits.mark', { name: habit.name }) : t('habits.addOne', { name: habit.name })
}

/* ---------- Where the hours open ---------- */

const timeline = ref<HTMLElement | null>(null)
const sticky = ref<HTMLElement | null>(null)
/** The height of the all-day area, which the routine blocks' names stick under. */
const stuckHeight = ref(0)
let stuckObserver: ResizeObserver | null = null
onMounted(() => {
  if (typeof ResizeObserver === 'undefined' || !sticky.value) return
  stuckObserver = new ResizeObserver(() => (stuckHeight.value = sticky.value?.offsetHeight ?? 0))
  stuckObserver.observe(sticky.value)
})
onBeforeUnmount(() => stuckObserver?.disconnect())

/**
 * Puts the first thing of the day (or now, on today) under the sticky parts. The page decides when:
 * a view or day opened anew, not a return that AppShell has already scrolled back.
 */
function scrollToStart(): void {
  const first = startMinute(daySegments(laneItems.value, props.day), isToday.value ? props.nowMin : null)
  scrollTimeline(timeline.value, sticky.value, first * ppm.value)
}

defineExpose({ scrollToStart })
</script>

<template>
  <div class="day-view">
    <!-- All-day area: sticky under the head. -->
    <div ref="sticky" class="sticky z-10 bg-bg shadow-[0_1px_0_var(--c-line)]" :style="{ top: 'var(--cal-head, 0px)' }">
      <div v-if="showAllDay" class="flex py-1.5 pr-2 md:pr-0">
        <span class="w-[3.25rem] shrink-0" aria-hidden="true" />
        <div
          class="grid min-h-6 min-w-0 flex-1 gap-1"
          :style="{ gridTemplateColumns: `repeat(${wide ? (columns ?? 3) : 2}, minmax(0, 1fr))` }"
          @click="onAllDayClick"
        >
          <template v-if="skeleton">
            <span class="skeleton h-6 !rounded-[0.375rem]" />
          </template>
          <button
            v-if="chipHabits.length"
            type="button"
            class="press flex h-6 min-w-0 items-center gap-1.5 rounded-[0.375rem] bg-habit-soft px-2 text-caption font-medium text-habit"
            :aria-expanded="habitsOpen"
            @click="habitsOpen = !habitsOpen"
          >
            <span class="num truncate">{{ $t('calendar.habits', { done: habitsDone, total: chipHabits.length }) }}</span>
            <ChevronUp class="toggle ml-auto size-3.5 shrink-0" :class="{ 'is-closed': !habitsOpen }" :stroke-width="2.4" aria-hidden="true" />
          </button>
          <CalendarChip v-for="item in shownChips" :key="item.key" :item="item" @click="emit('open', item)" />
          <button
            v-if="hiddenChips"
            type="button"
            class="press flex h-6 items-center rounded-[0.375rem] px-2 text-caption font-medium text-ink-2 hover:bg-ink/5"
            @click="expanded = true"
          >
            {{ $t('calendar.more', { n: hiddenChips }) }}
          </button>
        </div>
      </div>

      <!-- The day's habits, opened from their chip: past days can be logged, future ones only shown. -->
      <Transition name="habits">
        <div v-if="habitsOpen && chipHabits.length" class="flex flex-wrap gap-x-1 gap-y-2 pt-1 pb-2.5 pl-[3.25rem] pr-2 md:pr-0">
          <div v-for="item in chipHabits" :key="item.key" class="flex w-16 flex-col items-center gap-1" :class="{ 'pointer-events-none opacity-55': item.state === 'future' }">
            <HabitButton
              v-if="perDay(item)"
              :habit="perDay(item)!"
              size="sm"
              :label="habitLabel(perDay(item)!)"
              :aria-disabled="item.state === 'future' || undefined"
              @tap="emit('habitTap', item)"
              @hold="emit('habitHold', item)"
            />
            <span v-else class="grid size-9 place-items-center rounded-full bg-habit-soft text-habit">
              <component :is="habitIcon(item.icon)" class="size-4" :stroke-width="2.2" />
            </span>
            <span class="w-full truncate text-center text-micro text-ink-2">{{ item.title }}</span>
          </div>
        </div>
      </Transition>
    </div>

    <!-- The hours. -->
    <div ref="timeline" class="relative flex select-none" :style="{ height: `${24 * hourHeight}px` }">
      <TimeGutter :hour-height="hourHeight" class="w-[3.25rem]" />
      <div class="relative min-w-0 flex-1 shadow-[inset_1px_0_0_var(--c-line)] mr-2 md:mr-0" :style="hourLines(hourHeight)">
        <!-- Routine lane -->
        <div v-if="routineSegs.length" class="absolute inset-y-0 left-1 w-[clamp(6.5rem,36%,14rem)]">
          <div
            v-for="segment in routineSegs"
            :key="segment.key"
            class="routine absolute right-0 overflow-clip"
            :class="[segment.fromPrev ? 'rounded-t-none' : 'rounded-t-[0.5rem]', segment.toNext ? 'rounded-b-none' : 'rounded-b-[0.5rem]']"
            :style="routineStyle(segment)"
          >
            <button
              type="button"
              class="absolute inset-0"
              :aria-label="`${segment.item.title}, ${segment.item.start}–${segment.item.end}`"
              @click="emit('routine', segment.item)"
            />
            <span class="rail pointer-events-none absolute inset-y-0 left-0 w-[3px]" aria-hidden="true" />
            <!-- The name stays in sight while the block is scrolled under the sticky parts (a long «Работа»). -->
            <div
              v-if="heightOf(segment) >= 22"
              class="routine-label pointer-events-none sticky flex min-w-0 flex-col py-[3px] pl-2.5 pr-1.5"
              :class="{ 'pr-11': wide && blockHabits.has(segment.key), 'beside-check': !wide && blockHabits.has(segment.key) }"
              :style="{ top: `calc(var(--cal-head, 0px) + ${stuckHeight}px)` }"
            >
              <span
                class="flex min-w-0 items-center gap-1.5 text-caption leading-[17px] font-semibold text-ink"
                :class="{ 'pr-10': !wide && blockHabits.has(segment.key) && heightOf(segment) < 60 }"
              >
                <component
                  :is="routineLook(segment.item.kind).icon"
                  v-if="heightOf(segment) >= 40"
                  class="size-3.5 shrink-0 text-(--rc)"
                  :stroke-width="2.2"
                  aria-hidden="true"
                />
                <span class="truncate">{{ segment.item.title }}</span>
              </span>
              <span
                v-if="heightOf(segment) >= 40"
                class="num truncate text-micro leading-[15px] text-ink-2"
                :class="{ 'pr-10': !wide && blockHabits.has(segment.key) }"
              >
                {{ segment.item.start }}<span class="end">–{{ segment.item.end }}</span>
              </span>
            </div>
            <!-- The habit's check: beside the name on a wide screen, under it in a phone's narrower lane. -->
            <span v-if="blockHabits.has(segment.key)" class="absolute right-1 z-[1]" :class="wide ? 'top-1' : 'bottom-1'">
              <span
                v-if="blockHabits.get(segment.key)!.state === 'future' || !perDay(blockHabits.get(segment.key)!)"
                class="m-1 block size-7 rounded-full shadow-[inset_0_0_0_2px_var(--rc)] opacity-35"
                aria-hidden="true"
              />
              <HabitButton
                v-else
                :habit="perDay(blockHabits.get(segment.key)!)!"
                size="sm"
                :label="habitLabel(perDay(blockHabits.get(segment.key)!)!)"
                @tap="emit('habitTap', blockHabits.get(segment.key)!)"
                @hold="emit('habitHold', blockHabits.get(segment.key)!)"
              />
            </span>
          </div>
        </div>

        <!-- Things lane -->
        <div
          ref="lane"
          class="absolute inset-y-0 right-0"
          :class="routineSegs.length ? 'left-[calc(clamp(6.5rem,36%,14rem)_+_0.5rem)]' : 'left-1'"
          @click="onLaneClick"
          @pointerdown="drag.fromLane($event, 0)"
        >
          <template v-if="skeleton">
            <span class="skeleton absolute inset-x-0 !rounded-[0.375rem]" :style="{ top: `${9 * hourHeight}px`, height: `${hourHeight - 1}px` }" />
            <span class="skeleton absolute left-0 w-2/3 !rounded-[0.375rem]" :style="{ top: `${13 * hourHeight}px`, height: `${hourHeight * 1.5 - 1}px` }" />
          </template>
          <ThingBlock
            v-for="segment in placed"
            :key="segment.key"
            :item="asThing(segment.item)"
            :height="blockHeight(segment)"
            :controls="hasControls(segment)"
            :habit="segment.item.type === 'habit' ? perDay(segment.item) : null"
            :flat-top="segment.fromPrev"
            :flat-bottom="segment.toNext"
            :draggable="wide && segment.item.type === 'event'"
            :lifted="drag.state.value?.mode !== 'create' && drag.state.value?.key === segment.key"
            :time-text="dragTime(segment)"
            :style="blockStyle(segment)"
            @open="emit('open', segment.item)"
            @toggle="emit('toggleTask', segment.item as TaskItem)"
            @habit-tap="emit('habitTap', segment.item as HabitItem)"
            @habit-hold="emit('habitHold', segment.item as HabitItem)"
            @grab="(event, mode) => grab(event, segment, mode)"
          />
          <div
            v-if="ghost"
            class="pointer-events-none absolute inset-x-0 z-[2] rounded-[0.375rem] px-1.5 py-[3px] text-micro font-semibold text-white opacity-85"
            :style="{ top: ghost.top, height: ghost.height, backgroundColor: 'color-mix(in srgb, #5f55d8 78%, #000)' }"
          >
            {{ ghost.text }}
          </div>
        </div>

        <!-- Now -->
        <div
          v-if="isToday"
          class="pointer-events-none absolute right-0 -left-1 z-[4] h-0.5 -translate-y-1/2 bg-danger"
          :style="{ top: `${nowMin * ppm}px` }"
          aria-hidden="true"
        >
          <span class="absolute top-1/2 -left-1 size-2.5 -translate-y-1/2 rounded-full bg-danger shadow-[0_0_0_3px_color-mix(in_srgb,var(--c-danger)_22%,transparent)]" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rail {
  background: var(--rc);
}

/* Beside a phone's habit check a narrow block shows only when it starts. */
.routine-label {
  container-type: inline-size;
}

.beside-check .end {
  display: none;
}

@container (min-width: 7.75rem) {
  .beside-check .end {
    display: inline;
  }
}

.toggle {
  transition: transform var(--dur-base) var(--ease-standard);
}

.toggle.is-closed {
  transform: rotate(180deg);
}

.habits-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}

.habits-leave-active {
  transition: opacity var(--dur-fast) var(--ease-exit);
}

.habits-enter-from {
  opacity: 0;
  transform: translateY(-4px);
}

.habits-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .toggle,
  .habits-enter-from {
    transition: none;
    transform: none;
  }
}
</style>
