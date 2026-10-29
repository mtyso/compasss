<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { dateAtNoon } from '@/lib/dates'
import { formatDayHeading, formatMonthShort } from '@/lib/format'
import { trimYear, yearColumns } from '@/lib/habits'
import type { HabitDayState, HabitHistory } from '@/stores/habits'
import { useSettings } from '@/stores/settings'

type Day = HabitHistory['days'][number]

/**
 * A habit's year as a map: a column of seven squares per week from Monday, oldest on the left,
 * in the habit's colour. A kept day is filled; a day a spare covered is a ring; a missed day is
 * grey (pale colour when part of a count was done); a pause is hatched. The map starts at the
 * habit's first week; on a phone it scrolls and opens at today; a tap on a square tells that day.
 * The legend names only what the map shows.
 */
const props = defineProps<{ days: Day[]; color: string; target: number; unit: string | null; type: 'check' | 'count' | 'limit' }>()
const { t } = useI18n()
const settings = useSettings()

const shown = computed(() => trimYear(props.days))
const columns = computed(() => yearColumns(shown.value))
const keptCount = computed(() => props.days.filter((d) => d.state === 'kept').length)
const has = computed(() => new Set(shown.value.map((d) => d.state)))
const selected = ref<Day | null>(null)
const scroller = ref<HTMLElement | null>(null)

const STEP = 12 // px: a 10px square and its gap

function cellStyle(day: Day): Record<string, string> | undefined {
  if (day.state === 'kept') return { backgroundColor: props.color }
  if (day.state === 'spared') return { boxShadow: `inset 0 0 0 1.5px ${props.color}` }
  // Part of a count done: the colour, faint.
  if (day.state === 'missed' && day.value > 0 && props.type === 'count') return { backgroundColor: `color-mix(in srgb, ${props.color} 35%, transparent)` }
  return undefined
}

const stateClass: Record<HabitDayState, string> = {
  kept: '',
  spared: '',
  missed: 'bg-ink/12',
  paused: 'paused',
  open: 'open',
  off: 'bg-ink/[0.04]',
  none: '',
}

function pick(event: MouseEvent): void {
  const index = Number((event.target as HTMLElement).dataset.index)
  const day = Number.isFinite(index) ? shown.value[index] : undefined
  selected.value = day && day.state !== 'none' && selected.value !== day ? day : null
}

const selectedText = computed(() => {
  const day = selected.value
  if (!day) return null
  const parts = [formatDayHeading(dateAtNoon(day.date), settings.locale), t(`habits.states.${day.state}`)]
  if (props.type !== 'check' && day.value > 0) {
    const amount = props.type === 'count' ? t('habits.ofTarget', { value: day.value, target: props.target }) : String(day.value)
    parts.push(props.unit ? `${amount} ${props.unit}` : amount)
  }
  return parts.join(' · ')
})

function toEnd(): void {
  void nextTick(() => {
    if (scroller.value) scroller.value.scrollLeft = scroller.value.scrollWidth
  })
}
onMounted(toEnd)
watch(() => shown.value.length, toEnd)
</script>

<template>
  <div class="flex flex-col gap-2">
    <div ref="scroller" class="no-scrollbar -mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
      <div class="relative w-max" :style="{ paddingTop: '1.25rem' }">
        <span
          v-for="m in columns.months"
          :key="m.month"
          class="absolute top-0 text-micro text-ink-3"
          :style="{ left: `${m.column * STEP}px` }"
          aria-hidden="true"
        >
          {{ formatMonthShort(m.month, settings.locale) }}
        </span>
        <div class="grid grid-flow-col grid-rows-7 gap-[2px]" role="img" :aria-label="$t('habits.yearLabel', { n: keptCount }, keptCount)" @click="pick">
          <template v-for="(week, w) in columns.weeks" :key="w">
            <span
              v-for="(day, d) in week"
              :key="`${w}-${d}`"
              :data-index="day ? w * 7 + d : undefined"
              class="cell size-[10px] rounded-[2.5px]"
              :class="[day ? stateClass[day.state] : '', { 'is-selected': day && selected === day }]"
              :style="day ? cellStyle(day) : undefined"
            />
          </template>
        </div>
      </div>
    </div>

    <p class="min-h-5 text-caption text-ink-2" aria-live="polite">
      <template v-if="selectedText">{{ selectedText }}</template>
      <span v-else class="flex flex-wrap items-center gap-x-3 gap-y-1 text-ink-3">
        <span class="flex items-center gap-1.5"><span class="cell size-[10px] rounded-[2.5px]" :style="{ backgroundColor: color }" />{{ $t('habits.states.kept') }}</span>
        <span v-if="has.has('spared')" class="flex items-center gap-1.5"><span class="cell size-[10px] rounded-[2.5px]" :style="{ boxShadow: `inset 0 0 0 1.5px ${color}` }" />{{ $t('habits.states.spared') }}</span>
        <span v-if="has.has('missed')" class="flex items-center gap-1.5"><span class="cell size-[10px] rounded-[2.5px] bg-ink/12" />{{ $t('habits.states.missed') }}</span>
        <span v-if="has.has('paused')" class="flex items-center gap-1.5"><span class="cell paused size-[10px] rounded-[2.5px]" />{{ $t('habits.states.paused') }}</span>
      </span>
    </p>
  </div>
</template>

<style scoped>
.cell {
  display: block;
  flex-shrink: 0;
}

/* A pause: hatched, as one-off spending is in the report. */
.paused {
  background-image: repeating-linear-gradient(-45deg, color-mix(in srgb, var(--c-ink) 35%, transparent) 0 1px, transparent 1px 3px);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c-ink) 18%, transparent);
}

.open {
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c-ink) 35%, transparent);
}

.is-selected {
  outline: 1.5px solid var(--c-ink);
  outline-offset: 1px;
}
</style>
