<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { eventFill, isAllDayEvent, monthGrid, monthRowBars, moreCounts, type WeekBar } from '@/lib/calendar'
import { formatDayMonth } from '@/lib/format'
import { weekdayNames, weekdayShort } from '@/lib/tasks'
import type { CalendarItem, Layer } from '@/stores/calendar'
import { useSettings } from '@/stores/settings'
import CalendarChip from './CalendarChip.vue'

/**
 * A month as whole weeks, Monday first: each cell has its day and up to three lines (bars of
 * several days run across their week), then «ещё N». A tap on a cell opens that day; on a wide
 * screen a tap on a line opens the thing itself. No habits.
 */
const props = defineProps<{
  anchor: string
  today: string
  items: CalendarItem[]
  layers: ReadonlySet<Layer>
  wide: boolean
  skeleton: boolean
}>()
const emit = defineEmits<{ open: [item: CalendarItem]; pickDay: [day: string] }>()

const { t } = useI18n()
const settings = useSettings()

const LINES = 3
const month = computed(() => props.anchor.slice(0, 7))
const weeks = computed(() => {
  const days = monthGrid(month.value)
  return Array.from({ length: days.length / 7 }, (_, i) => days.slice(i * 7, i * 7 + 7))
})

/** Sizes in px: the day number's row, one line and the gap under it. */
const dims = computed(() => (props.wide ? { number: 30, line: 20, gap: 2, row: 116 } : { number: 24, line: 16, gap: 1, row: 88 }))

const rows = computed(() =>
  weeks.value.map((week) => {
    const bars = monthRowBars(props.items, week, props.layers)
    return { week, bars: bars.filter((bar) => bar.lane < LINES), more: moreCounts(bars, LINES) }
  }),
)

function lineStyle(bar: WeekBar): Record<string, string> {
  const { line, gap } = dims.value
  const inset = (cut: boolean) => (cut ? 0 : 2)
  return {
    top: `${bar.lane * (line + gap)}px`,
    height: `${line}px`,
    left: `calc(${(bar.startCol / 7) * 100}% + ${inset(bar.cutStart)}px)`,
    width: `calc(${((bar.endCol - bar.startCol + 1) / 7) * 100}% - ${inset(bar.cutStart) + inset(bar.cutEnd)}px)`,
  }
}

function moreStyle(col: number): Record<string, string> {
  const { line, gap } = dims.value
  return { top: `${LINES * (line + gap)}px`, left: `${(col / 7) * 100}%`, width: `${100 / 7}%` }
}

/** A line drawn as a chip: everything on a phone; things without a time on a wide screen. */
function asChip(item: CalendarItem): boolean {
  if (!props.wide) return true
  if (item.type === 'event') return isAllDayEvent(item)
  return item.type !== 'task' || !item.start
}

/** «09:00» before a timed line on a wide screen; a night event's second day starts at midnight. */
function lineTime(item: CalendarItem, day: string): string {
  if (!('start' in item) || !item.start) return ''
  return item.date < day ? '00:00' : item.start
}

function dotColor(item: CalendarItem): string {
  return item.type === 'event' ? eventFill(item.color) : 'var(--c-task)'
}

function dayLabel(day: string): string {
  return `${weekdayShort(day, t)}, ${formatDayMonth(day, settings.locale)}`
}
</script>

<template>
  <div class="month-view">
    <div class="sticky z-10 grid grid-cols-7 bg-bg shadow-[0_1px_0_var(--c-line)]" :style="{ top: 'var(--cal-head, 0px)' }" aria-hidden="true">
      <span v-for="name in weekdayNames(t)" :key="name" class="py-1.5 text-center text-micro font-medium text-ink-3 md:px-2.5 md:text-left">{{ name }}</span>
    </div>

    <div class="shadow-[0_1px_0_var(--c-line)]">
      <div v-for="(row, r) in rows" :key="row.week[0]" class="relative grid grid-cols-7" :style="{ height: `${dims.row}px` }">
        <button
          v-for="(day, c) in row.week"
          :key="day"
          type="button"
          class="cell flex flex-col items-center pt-1 md:items-start md:px-1.5 md:pt-1.5"
          :class="{ 'border-l border-line': c > 0, 'border-t border-line': r > 0 }"
          :aria-label="dayLabel(day)"
          :aria-current="day === today ? 'date' : undefined"
          @click="emit('pickDay', day)"
        >
          <span
            class="num grid h-5 min-w-5 place-items-center rounded-full px-1 text-caption font-semibold md:h-6 md:min-w-6"
            :class="day === today ? 'bg-money text-on-money' : day.slice(0, 7) === month ? 'text-ink' : 'font-medium text-ink-3'"
          >
            {{ Number(day.slice(8, 10)) }}
          </span>
        </button>

        <!-- The lines lie over the cells; only on a wide screen do they take taps of their own. -->
        <div class="pointer-events-none absolute inset-x-0" :style="{ top: `${dims.number}px` }">
          <template v-if="skeleton && r % 2 === 1">
            <span class="skeleton absolute !rounded-[0.25rem]" :style="{ top: '0px', height: `${dims.line}px`, left: `calc(${((r + 1) % 7) * (100 / 7)}% + 2px)`, width: `calc(${100 / 7}% - 4px)` }" />
          </template>
          <template v-for="bar in row.bars" :key="`${bar.item.key}:${bar.startCol}`">
            <CalendarChip
              v-if="asChip(bar.item)"
              :item="bar.item"
              :size="wide ? 'sm' : 'xs'"
              :as="wide ? 'button' : 'span'"
              :cut-start="bar.cutStart"
              :cut-end="bar.cutEnd"
              class="absolute"
              :class="{ 'pointer-events-auto': wide }"
              :style="lineStyle(bar)"
              @click="emit('open', bar.item)"
            />
            <button
              v-else
              type="button"
              class="pointer-events-auto absolute flex min-w-0 items-center gap-1.5 rounded-[0.375rem] px-1.5 text-micro hover:bg-ink/5"
              :style="lineStyle(bar)"
              :title="bar.item.title"
              @click="emit('open', bar.item)"
            >
              <span class="size-1.5 shrink-0 rounded-full" :style="{ backgroundColor: dotColor(bar.item) }" aria-hidden="true" />
              <span class="num shrink-0 text-ink-3">{{ lineTime(bar.item, row.week[bar.startCol]!) }}</span>
              <span class="truncate font-medium text-ink" :class="{ 'text-ink-3 line-through': bar.item.type === 'task' && bar.item.done }">{{ bar.item.title }}</span>
            </button>
          </template>
          <template v-for="(count, col) in row.more" :key="col">
            <span
              v-if="count"
              class="absolute truncate px-1 text-micro font-medium text-ink-2 md:px-2"
              :class="wide ? 'leading-[18px]' : 'text-center leading-3'"
              :style="moreStyle(col)"
            >
              {{ $t('calendar.more', { n: count }) }}
            </span>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cell {
  transition: background-color var(--dur-fast) var(--ease-standard);
}

@media (hover: hover) {
  .cell:hover {
    background-color: color-mix(in srgb, var(--c-ink) 3%, transparent);
  }
}

.cell:active {
  background-color: color-mix(in srgb, var(--c-ink) 6%, transparent);
}
</style>
