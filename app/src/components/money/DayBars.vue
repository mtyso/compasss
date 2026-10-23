<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { haptics } from '@/lib/haptics'
import { dateAtNoon } from '@/lib/dates'
import { formatDayHeading, formatMoney } from '@/lib/format'
import type { ReportDay } from '@/lib/report'
import { useSettings } from '@/stores/settings'

/**
 * The month day by day: a bar for every day, as tall as what was spent, with the one-off part
 * hatched on top so a big purchase does not read as a habit. A dashed line marks the average day.
 *
 * The readout above works like the display of an instrument: it shows the average day, and while
 * a finger runs along the bars (or the pointer hovers, or the arrow keys move) it shows that day,
 * the digits rolling from one value to the next. Days still ahead are dots on the baseline.
 *
 * `today` is the day of a month still going (days after it are ahead); null for a month that is over.
 */
const props = defineProps<{ days: ReportDay[]; today: string | null; tracked: boolean }>()

const settings = useSettings()
const { t } = useI18n()

const ahead = (day: ReportDay) => props.today !== null && day.date > props.today
const regular = (day: ReportDay) => day.expense_uzs - day.one_off_uzs

// A month the app did not see from its start is averaged from its first spending on.
const counted = computed(() => {
  const gone = props.days.filter((day) => !ahead(day))
  if (props.tracked) return gone
  const first = gone.findIndex((day) => day.expense_uzs > 0)
  return first < 0 ? [] : gone.slice(first)
})
const average = computed(() =>
  counted.value.length ? Math.round(counted.value.reduce((sum, day) => sum + regular(day), 0) / counted.value.length) : 0,
)

// The scale follows regular spending; a day lifted far above it by a one-off is cut at the top.
const top = computed(() => Math.max(0, ...props.days.map(regular)) || Math.max(1, ...props.days.map((day) => day.expense_uzs)))
const bars = computed(() =>
  props.days.map((day, index) => {
    const height = Math.min(1, day.expense_uzs / top.value)
    return {
      ...day,
      index,
      ahead: ahead(day),
      height,
      // The one-off part, as a share of the bar.
      oneOff: day.expense_uzs ? Math.min(1, day.one_off_uzs / day.expense_uzs) : 0,
      cut: day.expense_uzs > top.value * 1.04,
      isToday: day.date === props.today,
    }
  }),
)
const averageAt = computed(() => Math.min(1, average.value / top.value))

/** Day numbers under the axis: the first, every tenth, the last; today's when the month is going. */
const ticks = computed(() => {
  const last = props.days.length
  const marks = new Set([1, 10, 20, last])
  if (props.today) {
    const day = Number(props.today.slice(8, 10))
    for (const mark of [...marks]) if (mark !== day && Math.abs(mark - day) < 3) marks.delete(mark)
    marks.add(day)
  }
  return [...marks].sort((a, b) => a - b)
})

const picked = ref<number | null>(null)
const day = computed(() => (picked.value === null ? null : (props.days[picked.value] ?? null)))

function pick(index: number | null, feel = false): void {
  if (index !== null && (index < 0 || index >= props.days.length || bars.value[index]!.ahead)) return
  if (index === picked.value) return
  picked.value = index
  if (feel && index !== null) haptics.selection()
}

const plot = ref<HTMLElement | null>(null)
let pressed = false

function indexAt(event: PointerEvent): number {
  const box = plot.value!.getBoundingClientRect()
  const share = Math.min(0.999, Math.max(0, (event.clientX - box.left) / box.width))
  return Math.floor(share * props.days.length)
}

function onDown(event: PointerEvent): void {
  pressed = true
  const index = indexAt(event)
  // A tap on the day already shown puts the readout back to the average.
  pick(index === picked.value && event.pointerType !== 'mouse' ? null : index, true)
}

function onMove(event: PointerEvent): void {
  if (event.pointerType === 'mouse' || pressed) pick(indexAt(event), event.pointerType !== 'mouse')
}

function onLeave(event: PointerEvent): void {
  pressed = false
  if (event.pointerType === 'mouse') pick(null)
}

function onKey(event: KeyboardEvent): void {
  const last = bars.value.filter((bar) => !bar.ahead).length - 1
  const from = picked.value ?? last
  const next = { ArrowLeft: from - 1, ArrowRight: picked.value === null ? last : from + 1, Home: 0, End: last }[event.key]
  if (event.key === 'Escape') pick(null)
  else if (next !== undefined) pick(Math.min(last, Math.max(0, next)))
  else return
  event.preventDefault()
}

const label = computed(() => (day.value ? formatDayHeading(dateAtNoon(day.value.date), settings.locale) : t('report.perDay')))
const amount = computed(() => (day.value ? day.value.expense_uzs : average.value))
const note = computed(() => {
  if (!day.value) return t('report.perDayHint')
  if (!day.value.expense_uzs) return t('report.dayNone')
  if (!day.value.one_off_uzs) return ''
  if (day.value.one_off_uzs === day.value.expense_uzs) return t('report.oneOffShort')
  return t('report.oneOff', { amount: formatMoney(day.value.one_off_uzs, 'UZS', settings.locale) })
})
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex min-h-[4.25rem] flex-col gap-1" aria-live="polite">
      <span class="text-caption text-ink-3">{{ label }}</span>
      <MoneyAmount :amount="amount" animate class="text-[1.5rem] leading-none font-semibold tracking-[-0.02em]" />
      <span class="min-h-[1.1em] text-caption text-ink-3">{{ note }}</span>
    </div>

    <div class="flex flex-col gap-2">
      <div
        ref="plot"
        class="plot relative h-28 touch-pan-y md:h-36 outline-none select-none"
        :class="{ picking: picked !== null }"
        tabindex="0"
        role="group"
        :aria-label="$t('report.byDay')"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="pressed = false"
        @pointercancel="pressed = false"
        @pointerleave="onLeave"
        @keydown="onKey"
        @blur="pick(null)"
      >
        <div class="absolute inset-0 flex items-end gap-[3px]" aria-hidden="true">
          <span
            v-for="bar in bars"
            :key="bar.date"
            class="slot relative flex h-full flex-1 items-end justify-center"
            :class="{ on: picked === bar.index }"
          >
            <span v-if="bar.ahead" class="ahead" />
            <span v-else-if="!bar.expense_uzs" class="stub" />
            <span
              v-else
              class="bar relative w-full max-w-4 overflow-hidden"
              :class="{ cut: bar.cut, today: bar.isToday }"
              :style="{ height: `${Math.max(bar.height * 100, 2.5)}%`, transitionDelay: `${bar.index * 14}ms` }"
            >
              <span v-if="bar.oneOff" class="one-off absolute inset-x-0 top-0" :style="{ height: `${bar.oneOff * 100}%` }" />
            </span>
          </span>
        </div>
        <span v-if="average > 0" class="average pointer-events-none absolute inset-x-0" :style="{ bottom: `${averageAt * 100}%` }" aria-hidden="true" />
      </div>

      <div class="relative h-4 text-micro text-ink-3" aria-hidden="true">
        <span
          v-for="tick in ticks"
          :key="tick"
          class="num absolute -translate-x-1/2"
          :class="{ 'font-semibold text-ink': today && tick === Number(today.slice(8, 10)) }"
          :style="{ left: `${((tick - 0.5) / days.length) * 100}%` }"
        >
          {{ tick }}
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bar {
  --bar: color-mix(in srgb, var(--c-ink) 58%, var(--c-bg));
  border-radius: 3px 3px 1.5px 1.5px;
  background-color: var(--bar);
  transform-origin: bottom;
  transition:
    transform 560ms var(--ease-spring),
    opacity 160ms var(--ease-standard);
}

/* The one-off part: the same colour, hatched, so the regular day under it still reads. */
.one-off {
  background-color: var(--c-bg);
  background-image: repeating-linear-gradient(-45deg, var(--bar) 0 1.5px, transparent 1.5px 4px);
  box-shadow: inset 0 0 0 1px var(--bar);
  border-radius: inherit;
}

/* A bar cut at the top of the scale ends in a gap, the way a chart marks a broken axis. */
.cut::after {
  content: '';
  position: absolute;
  inset-inline: 0;
  top: 5px;
  height: 2px;
  background-color: var(--c-bg);
}

.today {
  --bar: var(--c-ink);
}

.stub,
.ahead {
  display: block;
  border-radius: 999px;
  background-color: color-mix(in srgb, var(--c-ink) 12%, transparent);
  transition: opacity 160ms var(--ease-standard);
}

.stub {
  width: 100%;
  max-width: 1rem;
  height: 2px;
}

.ahead {
  width: 3px;
  height: 3px;
}

.average {
  height: 0;
  border-top: 1px dashed color-mix(in srgb, var(--c-ink) 55%, transparent);
  transition: bottom 560ms var(--ease-spring);
}

/* While a day is picked the others step back and the picked bar takes the full ink. */
.picking .slot:not(.on) :is(.bar, .stub) {
  opacity: 0.32;
}

.picking .on .bar {
  --bar: var(--c-ink);
}

.picking .on .stub {
  background-color: var(--c-ink);
}

.plot:focus-visible {
  outline: 2px solid var(--c-focus);
  outline-offset: 4px;
  border-radius: 0.375rem;
}

/* The bars rise from the baseline one after another when the month appears. */
@starting-style {
  .bar {
    transform: scaleY(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .bar {
    transition: opacity 160ms var(--ease-standard);
  }
}
</style>
