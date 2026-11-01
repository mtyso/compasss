<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDownRight, ArrowUpRight } from 'lucide-vue-next'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { lastDay, paceAt, runningTotals, type MonthAnalytics } from '@/lib/analytics'
import { haptics } from '@/lib/haptics'
import { formatDayMonth, formatMoney, formatMonthName } from '@/lib/format'
import { useSettings } from '@/stores/settings'

/**
 * The month as a running total: what was spent from the 1st up to each day, a solid line up to today, with
 * the month before as a dashed line under or over it. Where the two part is the news: «к 16 октября на 22%
 * больше, чем месяцем раньше к этому дню». The readout follows a finger along the line like DayBars does.
 */
const props = defineProps<{ data: Pick<MonthAnalytics, 'month' | 'days' | 'prev' | 'today'> }>()

const settings = useSettings()
const { t } = useI18n()

const last = computed(() => lastDay(props.data))
const now = computed(() => runningTotals(props.data.days.map((d) => d.expense_uzs)).slice(0, last.value))
const then = computed(() => (props.data.prev ? runningTotals(props.data.prev.days.map((d) => d.expense_uzs)) : []))
/** The axis runs over the longer of the two months. */
const span = computed(() => Math.max(props.data.days.length, then.value.length))
// A little air over the higher line, so it does not run along the top edge.
const top = computed(() => Math.max(1, now.value.at(-1) ?? 0, then.value.at(-1) ?? 0) * 1.08)

const x = (day: number) => ((day - 1) / Math.max(1, span.value - 1)) * 100
const y = (value: number) => 100 - (value / top.value) * 100
const line = (values: number[]) => values.map((value, i) => `${i ? 'L' : 'M'}${x(i + 1).toFixed(2)},${y(value).toFixed(2)}`).join(' ')

const nowPath = computed(() => line(now.value))
const nowArea = computed(() => (now.value.length ? `${nowPath.value} L${x(now.value.length).toFixed(2)},100 L0,100 Z` : ''))
const thenPath = computed(() => line(then.value))

const picked = ref<number | null>(null)
const point = computed(() => paceAt(props.data, picked.value ?? last.value))

function pick(day: number | null, feel = false): void {
  if (day !== null) day = Math.min(last.value, Math.max(1, day))
  if (day === picked.value) return
  picked.value = day
  if (feel && day !== null) haptics.selection()
}

const plot = ref<HTMLElement | null>(null)
let pressed = false

function dayAt(event: PointerEvent): number {
  const box = plot.value!.getBoundingClientRect()
  const share = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width))
  return Math.round(share * (span.value - 1)) + 1
}

function onDown(event: PointerEvent): void {
  pressed = true
  pick(dayAt(event), true)
}

function onMove(event: PointerEvent): void {
  if (event.pointerType === 'mouse' || pressed) pick(dayAt(event), event.pointerType !== 'mouse')
}

function onEnd(event: PointerEvent): void {
  pressed = false
  if (event.pointerType !== 'mouse' || event.type === 'pointerleave') pick(null)
}

function onKey(event: KeyboardEvent): void {
  const from = picked.value ?? last.value
  const next = { ArrowLeft: from - 1, ArrowRight: from + 1, Home: 1, End: last.value }[event.key]
  if (event.key === 'Escape') pick(null)
  else if (next !== undefined) pick(next)
  else return
  event.preventDefault()
}

const date = (day: number) => `${props.data.month}-${String(day).padStart(2, '0')}`
const label = computed(() => t('analytics.byDate', { date: formatDayMonth(date(point.value.day), settings.locale) }))
const thenLine = computed(() =>
  point.value.then === null
    ? t('analytics.noPrev')
    : t('analytics.thenLine', { amount: formatMoney(point.value.then, 'UZS', settings.locale) }),
)

const ticks = computed(() => {
  const marks = new Set([1, 10, 20, span.value])
  if (props.data.today) {
    for (const mark of [...marks]) if (mark !== last.value && Math.abs(mark - last.value) < 3) marks.delete(mark)
    marks.add(last.value)
  }
  return [...marks].sort((a, b) => a - b)
})

const legend = computed(() => ({
  now: formatMonthName(props.data.month, settings.locale),
  then: props.data.prev ? formatMonthName(props.data.prev.month, settings.locale) : null,
}))
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex min-h-[4.25rem] flex-col gap-1" aria-live="polite">
      <span class="text-caption text-ink-3">{{ label }}</span>
      <span class="flex items-baseline gap-2">
        <MoneyAmount :amount="point.now" animate class="text-[1.5rem] leading-none font-semibold tracking-[-0.02em]" />
        <span
          v-if="point.diff !== null && point.diff !== 0"
          class="num inline-flex items-center gap-0.5 text-sm font-semibold"
          :class="point.diff > 0 ? 'text-danger' : 'text-money'"
        >
          <component :is="point.diff > 0 ? ArrowUpRight : ArrowDownRight" class="size-4 self-center" :stroke-width="2.4" />{{ Math.abs(point.diff) }}%
        </span>
      </span>
      <span class="min-h-[1.1em] truncate text-caption text-ink-3">{{ thenLine }}</span>
    </div>

    <div class="flex flex-col gap-2">
      <div
        ref="plot"
        class="plot relative h-28 touch-pan-y outline-none select-none md:h-36"
        :class="{ picking: picked !== null }"
        tabindex="0"
        role="group"
        :aria-label="$t('analytics.modes.pace')"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onEnd"
        @pointercancel="onEnd"
        @pointerleave="onEnd"
        @keydown="onKey"
        @blur="pick(null)"
      >
        <svg class="lines absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
          <line x1="0" y1="100" x2="100" y2="100" class="base" vector-effect="non-scaling-stroke" />
          <path v-if="thenPath" :d="thenPath" class="then" vector-effect="non-scaling-stroke" />
          <path v-if="nowArea" :d="nowArea" class="area" />
          <path v-if="nowPath" :d="nowPath" class="now" vector-effect="non-scaling-stroke" />
        </svg>
        <!-- Dots and the hairline are HTML, so the stretched drawing does not squash them into ovals. -->
        <span v-if="picked !== null" class="hair absolute inset-y-0" :style="{ left: `${x(point.day)}%` }" aria-hidden="true" />
        <span
          v-if="point.then !== null"
          class="dot then-dot absolute"
          :style="{ left: `${x(Math.min(point.day, then.length))}%`, top: `${y(point.then)}%` }"
          aria-hidden="true"
        />
        <span v-if="now.length" class="dot now-dot absolute" :style="{ left: `${x(point.day)}%`, top: `${y(point.now)}%` }" aria-hidden="true" />
      </div>

      <div class="relative h-4 text-micro text-ink-3" aria-hidden="true">
        <span
          v-for="tick in ticks"
          :key="tick"
          class="num absolute -translate-x-1/2"
          :class="{ 'font-semibold text-ink': data.today && tick === last }"
          :style="{ left: `${x(tick)}%` }"
        >
          {{ tick }}
        </span>
      </div>

      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-caption text-ink-3">
        <span class="flex items-center gap-1.5"><span class="key now-key" aria-hidden="true" />{{ legend.now }}</span>
        <span v-if="legend.then" class="flex items-center gap-1.5">
          <span class="key then-key" aria-hidden="true" />{{ legend.then }}<template v-if="data.prev && !data.prev.tracked"> · {{ $t('analytics.prevPartial') }}</template>
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.lines {
  /* The line draws itself in from the left when the month appears. */
  animation: reveal 900ms var(--ease-sheet) both;
}

@keyframes reveal {
  from {
    clip-path: inset(-10% 100% -10% 0);
  }
  to {
    clip-path: inset(-10% -2% -10% 0);
  }
}

.base {
  stroke: color-mix(in srgb, var(--c-ink) 12%, transparent);
  stroke-width: 1;
}

.now {
  fill: none;
  stroke: var(--c-ink);
  stroke-width: 2;
  stroke-linejoin: round;
  stroke-linecap: round;
}

.area {
  fill: color-mix(in srgb, var(--c-ink) 6%, transparent);
}

.then {
  fill: none;
  stroke: color-mix(in srgb, var(--c-ink) 42%, transparent);
  stroke-width: 1.5;
  stroke-dasharray: 4 4;
  stroke-linejoin: round;
}

.hair {
  width: 0;
  border-left: 1px solid color-mix(in srgb, var(--c-ink) 22%, transparent);
}

.dot {
  width: 9px;
  height: 9px;
  margin: -4.5px 0 0 -4.5px;
  border-radius: 999px;
  box-shadow: 0 0 0 2px var(--c-bg);
}

.now-dot {
  background-color: var(--c-ink);
}

.then-dot {
  width: 7px;
  height: 7px;
  margin: -3.5px 0 0 -3.5px;
  background-color: color-mix(in srgb, var(--c-ink) 42%, var(--c-bg));
}

.key {
  display: inline-block;
  width: 14px;
  height: 0;
}

.now-key {
  border-top: 2px solid var(--c-ink);
}

.then-key {
  border-top: 1.5px dashed color-mix(in srgb, var(--c-ink) 42%, transparent);
}

.plot:focus-visible {
  outline: 2px solid var(--c-focus);
  outline-offset: 4px;
  border-radius: 0.375rem;
}

@media (prefers-reduced-motion: reduce) {
  .lines {
    animation: none;
  }
}
</style>
