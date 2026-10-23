<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  cascade,
  daySegments,
  DAY_MINUTES,
  eventDraft,
  fromMinutes,
  layoutColumns,
  moreCounts,
  roundSlot,
  routineSegments,
  startMinute,
  toMinutes,
  weekBars,
  type Columns,
  type Segment,
  type WeekBar,
} from '@/lib/calendar'
import { formatDayMonth } from '@/lib/format'
import { weekdayShort } from '@/lib/tasks'
import type { CalendarItem, EventInput, EventItem, Layer, RoutineOccurrence, TaskItem } from '@/stores/calendar'
import { useSettings } from '@/stores/settings'
import CalendarChip from './CalendarChip.vue'
import ThingBlock from './ThingBlock.vue'
import TimeGutter from './TimeGutter.vue'
import { routineColor } from './routineLook'
import { hourLines, scrollTimeline } from './timeline'
import { useCalendarDrag, type DragDrop } from './useCalendarDrag'

/**
 * A week, Monday to Sunday: day headers and the all-day bars stuck under the calendar's head, then
 * the hours with each day's things in columns and the routine as faint bands behind them. No habits.
 */
const props = defineProps<{
  days: string[]
  today: string
  nowMin: number
  items: CalendarItem[]
  routine: RoutineOccurrence[]
  layers: ReadonlySet<Layer>
  wide: boolean
  skeleton: boolean
}>()
const emit = defineEmits<{
  open: [item: CalendarItem]
  create: [draft: EventInput]
  pickDay: [day: string]
  drop: [drop: DragDrop]
}>()

const { t } = useI18n()
const settings = useSettings()

const hourHeight = computed(() => (props.wide ? 52 : 56))
const ppm = computed(() => hourHeight.value / 60)
const gutter = computed(() => (props.wide ? '3.25rem' : '2.5rem'))
const todayCol = computed(() => props.days.indexOf(props.today))

function dayLabel(day: string): string {
  return `${weekdayShort(day, t)}, ${formatDayMonth(day, settings.locale)}`
}

/* ---------- All-day row ---------- */

const bars = computed(() => weekBars(props.items, props.days, props.layers))
/** Lanes shown before «ещё N»: two on a phone, three on a wide screen. */
const lanes = computed(() => (props.wide ? 3 : 2))
const barHeight = computed(() => (props.wide ? 22 : 18))
const more = computed(() => moreCounts(bars.value, lanes.value, props.days.length))
const shownBars = computed(() => bars.value.filter((bar) => bar.lane < lanes.value))
const usedLanes = computed(() => Math.min(lanes.value, bars.value.reduce((max, bar) => Math.max(max, bar.lane + 1), 0)))
const allDayHeight = computed(() => {
  const rows = usedLanes.value + (more.value.some(Boolean) ? 1 : 0)
  return Math.max(props.wide ? 1 : 0, rows) * (barHeight.value + 2)
})

function barStyle(bar: WeekBar): Record<string, string> {
  return {
    top: `${bar.lane * (barHeight.value + 2)}px`,
    left: `calc(${(bar.startCol / 7) * 100}% + ${bar.cutStart ? 0 : 2}px)`,
    width: `calc(${((bar.endCol - bar.startCol + 1) / 7) * 100}% - ${(bar.cutStart ? 0 : 2) + (bar.cutEnd ? 0 : 2)}px)`,
  }
}

function moreStyle(col: number): Record<string, string> {
  return { top: `${usedLanes.value * (barHeight.value + 2)}px`, left: `${(col / 7) * 100}%`, width: `${100 / 7}%` }
}

/* ---------- Columns ---------- */

type Placed = Segment<CalendarItem> & Columns

const thingItems = computed(() => props.items.filter((item) => item.type === 'event' || item.type === 'task'))
const placed = computed(() => props.days.map((day) => layoutColumns(daySegments(thingItems.value, day), 22 / ppm.value)))
const bands = computed(() => props.days.map((day) => cascade(routineSegments(props.routine, day))))

function blockHeight(segment: { startMin: number; endMin: number }): number {
  return Math.max(22, (segment.endMin - segment.startMin) * ppm.value - 1)
}

function blockStyle(segment: Placed): Record<string, string> {
  const s = drag.state.value
  const moving = s && s.mode !== 'create' && s.key === segment.key ? s : null
  return {
    top: `${segment.startMin * ppm.value}px`,
    height: `${blockHeight(segment) + (moving?.mode === 'resize' ? moving.minutes * ppm.value : 0)}px`,
    left: `${(segment.col / segment.cols) * 100}%`,
    width: `calc(${((segment.span + 1) / segment.cols) * 100}% - 2px)`,
    ...(moving?.mode === 'move' ? { transform: `translate(${moving.dx}px, ${moving.dy}px)` } : {}),
  }
}

function bandStyle(band: Segment<RoutineOccurrence>): Record<string, string> {
  const color = routineColor(band.item.kind)
  return {
    top: `${band.startMin * ppm.value}px`,
    height: `${(band.endMin - band.startMin) * ppm.value}px`,
    '--rc': color,
    backgroundColor: `color-mix(in srgb, ${color} 9%, var(--c-bg))`,
  }
}

/** On a wide screen a band names itself at its top left, when it is tall enough and nothing sits there. */
function bandLabel(band: Segment<RoutineOccurrence>, col: number): boolean {
  if (!props.wide || band.fromPrev || (band.endMin - band.startMin) * ppm.value < 36) return false
  const labelEnd = band.startMin + 18 / ppm.value
  return !placed.value[col]!.some((s) => s.startMin < labelEnd && s.endMin > band.startMin)
}

function asThing(item: CalendarItem): EventItem | TaskItem {
  return item as EventItem | TaskItem
}

function dragTime(segment: Placed): string | null {
  const s = drag.state.value
  if (!s || s.mode === 'create' || s.key !== segment.key || segment.item.type !== 'event') return null
  const item = segment.item
  if (!item.start || !item.end) return null
  const shift = (time: string) => fromMinutes(toMinutes(time) + s.minutes)
  return props.wide ? `${s.mode === 'move' ? shift(item.start) : item.start}–${shift(item.end)}` : shift(s.mode === 'move' ? item.start : item.end)
}

/* ---------- Taps and drags ---------- */

/** The column elements, for the drag (not reactive: nothing renders from them). */
const columnEls: (HTMLElement | null)[] = []

const drag = useCalendarDrag({
  enabled: () => props.wide,
  pxPerMin: () => ppm.value,
  columns: () => columnEls,
  create: (col, startMin, endMin) => emit('create', eventDraft(props.days[col]!, startMin, endMin)),
  drop: (drop) => emit('drop', drop),
})

function grab(event: PointerEvent, segment: Placed, col: number, mode: 'move' | 'resize'): void {
  if (segment.item.type === 'event') drag.fromBlock(event, segment.item, segment, col, mode)
}

function onColumnClick(event: MouseEvent, col: number): void {
  const column = columnEls[col]
  if ((event.target as Element).closest('[data-block]') || !column) return
  const minute = (event.clientY - column.getBoundingClientRect().top) / ppm.value
  const start = Math.min(roundSlot(Math.max(0, minute)), DAY_MINUTES - 15)
  emit('create', eventDraft(props.days[col]!, start, Math.min(start + 60, DAY_MINUTES)))
}

function ghostIn(col: number): { top: string; height: string; text: string } | null {
  const s = drag.state.value
  if (s?.mode !== 'create' || s.col !== col) return null
  return { top: `${s.startMin * ppm.value}px`, height: `${(s.endMin - s.startMin) * ppm.value - 1}px`, text: fromMinutes(s.startMin) }
}

/* ---------- Where the hours open ---------- */

const timeline = ref<HTMLElement | null>(null)
const sticky = ref<HTMLElement | null>(null)

/** Puts the week's first timed thing (or now, in this week) under the sticky parts; the page decides when. */
function scrollToStart(): void {
  const segments = props.days.flatMap((day) => daySegments(thingItems.value, day))
  const first = startMinute(segments, todayCol.value >= 0 ? props.nowMin : null)
  scrollTimeline(timeline.value, sticky.value, first * ppm.value)
}

defineExpose({ scrollToStart })
</script>

<template>
  <div class="week-view">
    <div ref="sticky" class="sticky z-10 bg-bg shadow-[0_1px_0_var(--c-line)]" :style="{ top: 'var(--cal-head, 0px)' }">
      <!-- Day headers: a tap opens that day. -->
      <div class="flex pr-1 md:pr-0">
        <span class="shrink-0" :style="{ width: gutter }" />
        <div class="grid min-w-0 flex-1 grid-cols-7">
          <button
            v-for="day in days"
            :key="day"
            type="button"
            class="press flex flex-col items-center gap-0.5 rounded-[0.625rem] pt-1 pb-1.5 hover:bg-ink/[0.04]"
            :aria-label="dayLabel(day)"
            :aria-current="day === today ? 'date' : undefined"
            @click="emit('pickDay', day)"
          >
            <span class="text-micro font-medium" :class="day === today ? 'text-money' : 'text-ink-3'">{{ weekdayShort(day, t) }}</span>
            <span
              class="num grid size-8 place-items-center rounded-full text-section font-semibold"
              :class="day === today ? 'bg-money text-on-money' : 'text-ink'"
            >
              {{ Number(day.slice(8, 10)) }}
            </span>
          </button>
        </div>
      </div>

      <!-- All-day bars -->
      <div v-if="allDayHeight" class="flex pb-1.5 pr-1 md:pr-0">
        <span class="shrink-0" :style="{ width: gutter }" aria-hidden="true" />
        <div class="relative min-w-0 flex-1" :style="{ height: `${allDayHeight}px` }">
          <CalendarChip
            v-for="bar in shownBars"
            :key="`${bar.item.key}:${bar.startCol}`"
            :item="bar.item"
            :size="wide ? 'md' : 'sm'"
            :cut-start="bar.cutStart"
            :cut-end="bar.cutEnd"
            class="absolute"
            :style="barStyle(bar)"
            @click="emit('open', bar.item)"
          />
          <template v-for="(count, col) in more" :key="col">
            <button
              v-if="count"
              type="button"
              class="press absolute truncate rounded-[0.375rem] px-1 text-left text-micro font-medium text-ink-2 hover:bg-ink/5"
              :style="{ ...moreStyle(col), height: `${barHeight}px` }"
              @click="emit('pickDay', days[col]!)"
            >
              {{ $t('calendar.more', { n: count }) }}
            </button>
          </template>
        </div>
      </div>
    </div>

    <!-- The hours -->
    <div ref="timeline" class="relative flex select-none pr-1 md:pr-0" :style="{ height: `${24 * hourHeight}px` }">
      <TimeGutter :hour-height="hourHeight" :style="{ width: gutter }" />
      <div class="grid min-w-0 flex-1 grid-cols-7" :style="hourLines(hourHeight)">
        <div
          v-for="(day, col) in days"
          :key="day"
          :ref="(el) => (columnEls[col] = el as HTMLElement | null)"
          class="relative shadow-[inset_1px_0_0_var(--c-line)]"
          @click="onColumnClick($event, col)"
          @pointerdown="drag.fromLane($event, col)"
        >
          <!-- Routine: faint bands behind everything. -->
          <div v-for="band in bands[col]" :key="band.key" class="pointer-events-none absolute inset-x-px" :style="bandStyle(band)" aria-hidden="true">
            <span v-if="bandLabel(band, col)" class="band-label absolute top-1 left-1.5 max-w-[calc(100%_-_0.75rem)] truncate text-micro font-medium">
              {{ band.item.title }}
            </span>
          </div>

          <template v-if="skeleton && (col === 1 || col === 3)">
            <span class="skeleton absolute inset-x-0.5 !rounded-[0.375rem]" :style="{ top: `${(col === 1 ? 10 : 14) * hourHeight}px`, height: `${hourHeight - 1}px` }" />
          </template>

          <div class="absolute inset-y-0 right-px left-0.5">
            <ThingBlock
              v-for="segment in placed[col]"
              :key="segment.key"
              :item="asThing(segment.item)"
              :height="blockHeight(segment)"
              :compact="!wide"
              :flat-top="segment.fromPrev"
              :flat-bottom="segment.toNext"
              :draggable="wide && segment.item.type === 'event'"
              :lifted="drag.state.value?.mode !== 'create' && drag.state.value?.key === segment.key"
              :time-text="dragTime(segment)"
              :style="blockStyle(segment)"
              @open="emit('open', segment.item)"
              @grab="(event, mode) => grab(event, segment, col, mode)"
            />
            <div
              v-if="ghostIn(col)"
              class="pointer-events-none absolute inset-x-0 z-[2] rounded-[0.375rem] px-1 py-[3px] text-micro font-semibold text-white opacity-85"
              :style="{ top: ghostIn(col)!.top, height: ghostIn(col)!.height, backgroundColor: 'color-mix(in srgb, #5f55d8 78%, #000)' }"
            >
              {{ ghostIn(col)!.text }}
            </div>
          </div>

          <div
            v-if="col === todayCol"
            class="pointer-events-none absolute inset-x-0 z-[4] h-0.5 -translate-y-1/2 bg-danger"
            :style="{ top: `${nowMin * ppm}px` }"
            aria-hidden="true"
          >
            <span class="absolute top-1/2 -left-1 size-2.5 -translate-y-1/2 rounded-full bg-danger" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.band-label {
  color: color-mix(in srgb, var(--rc) 70%, var(--c-ink));
}
</style>
