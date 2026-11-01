<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { haptics } from '@/lib/haptics'
import { CATEGORY_COLORS } from '@/lib/palette'

export interface DonutRow {
  id: number
  name: string
  color?: string | null
  total: number
}

/**
 * The month's spending as a ring: a segment per category in its colour, biggest first from the top,
 * clockwise; slivers too thin to see go into a grey «Остальное». The middle reads the total until a
 * segment or a line of the legend is under the pointer or the finger (or selected): then its share in
 * percent, its name and its sum. A click selects the category, as a tap on its row in the list does.
 */
const props = defineProps<{ rows: DonutRow[]; selected: number | null }>()
const emit = defineEmits<{ select: [id: number] }>()

const { t } = useI18n()

const MIN_SHARE = 0.012
const LEGEND = 5
const REST = -1

const total = computed(() => props.rows.reduce((sum, row) => sum + Math.max(0, row.total), 0))

const segments = computed(() => {
  if (!total.value) return []
  const sorted = props.rows.filter((row) => row.total > 0).sort((a, b) => b.total - a.total)
  const shown = sorted.filter((row) => row.total / total.value >= MIN_SHARE)
  const rest = total.value - shown.reduce((sum, row) => sum + row.total, 0)
  const list = [
    ...shown.map((row) => ({ id: row.id, name: row.name, total: row.total, color: CATEGORY_COLORS[row.color ?? 'gray'] ?? CATEGORY_COLORS.gray! })),
    ...(rest > 0 ? [{ id: REST, name: t('analytics.rest'), total: rest, color: 'color-mix(in srgb, var(--c-ink) 22%, var(--c-bg))' }] : []),
  ]
  // Lengths in hundredths of the ring (pathLength 100), with a hairline gap between neighbours.
  const gap = list.length > 1 ? 0.7 : 0
  let start = 0
  return list.map((segment) => {
    const length = (segment.total / total.value) * 100
    const item = { ...segment, share: segment.total / total.value, start, dash: Math.max(0.35, length - gap) }
    start += length
    return item
  })
})

/** The legend: the biggest categories, then everything else as one line. */
const legend = computed(() => {
  const categories = segments.value.filter((s) => s.id !== REST)
  if (categories.length <= LEGEND + 1 && !segments.value.some((s) => s.id === REST)) return categories
  const head = categories.slice(0, LEGEND)
  const restTotal = total.value - head.reduce((sum, s) => sum + s.total, 0)
  return [...head, { id: REST, name: t('analytics.rest'), total: restTotal, share: restTotal / total.value, color: 'color-mix(in srgb, var(--c-ink) 22%, var(--c-bg))', start: 0, dash: 0 }]
})

const hovered = ref<number | null>(null)
/** What the middle shows: the one under the pointer, else the one selected, else the total. */
const focus = computed(() => {
  const id = hovered.value ?? props.selected
  if (id === null) return null
  return segments.value.find((s) => s.id === id) ?? legend.value.find((s) => s.id === id) ?? null
})

function percent(share: number): string {
  return share > 0 && share < 0.005 ? '<1%' : `${Math.round(share * 100)}%`
}

function hover(id: number | null, feel = false): void {
  if (id === hovered.value) return
  hovered.value = id
  if (feel && id !== null) haptics.selection()
}

function pick(id: number): void {
  if (id === REST) return
  emit('select', id)
  haptics.selection()
}
</script>

<template>
  <div v-if="segments.length" class="flex items-center gap-5 md:gap-8">
    <div class="relative size-36 shrink-0 md:size-44" @pointerleave="hover(null)">
      <svg viewBox="0 0 100 100" class="donut size-full -rotate-90" role="img" :aria-label="$t('analytics.donutLabel')">
        <circle cx="50" cy="50" r="40" pathLength="100" class="track" />
        <circle
          v-for="segment in segments"
          :key="segment.id"
          cx="50"
          cy="50"
          r="40"
          pathLength="100"
          class="segment"
          :class="{ dim: focus && focus.id !== segment.id, on: focus?.id === segment.id, rest: segment.id === REST }"
          :stroke="segment.color"
          :stroke-dasharray="`${segment.dash} ${100 - segment.dash}`"
          :stroke-dashoffset="-segment.start"
          @pointerenter="hover(segment.id, $event.pointerType !== 'mouse')"
          @pointerdown="hover(segment.id, true)"
          @click="pick(segment.id)"
        >
          <title>{{ segment.name }} · {{ percent(segment.share) }}</title>
        </circle>
      </svg>
      <!-- The middle, like the readout of an instrument. -->
      <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-7 text-center" aria-live="polite">
        <template v-if="focus">
          <span class="num text-[1.5rem] leading-none font-semibold tracking-[-0.02em] md:text-[1.75rem]">{{ percent(focus.share) }}</span>
          <span class="mt-1 line-clamp-2 text-caption leading-tight text-ink-2">{{ focus.name }}</span>
          <MoneyAmount :amount="focus.total" class="mt-0.5 text-micro text-ink-3" />
        </template>
        <template v-else>
          <span class="text-caption text-ink-3">{{ $t('analytics.donutTotal') }}</span>
          <MoneyAmount :amount="total" bare animate class="text-[1rem] leading-tight font-semibold md:text-[1.125rem]" />
          <span class="text-micro text-ink-3">{{ $t('quickAdd.som') }}</span>
        </template>
      </div>
    </div>

    <ul class="flex min-w-0 flex-1 flex-col gap-0.5" @pointerleave="hover(null)">
      <li v-for="item in legend" :key="item.id">
        <button
          type="button"
          class="press-soft -mx-2 flex w-[calc(100%+1rem)] items-center gap-2.5 rounded-control px-2 py-1.5 text-left"
          :class="{ 'opacity-45': focus && focus.id !== item.id, 'cursor-default': item.id === REST }"
          @pointerenter="hover(item.id)"
          @focus="hover(item.id)"
          @blur="hover(null)"
          @click="pick(item.id)"
        >
          <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: item.color }" aria-hidden="true" />
          <span class="min-w-0 flex-1 truncate text-[0.9375rem]">{{ item.name }}</span>
          <span class="num shrink-0 text-[0.9375rem] font-semibold">{{ percent(item.share) }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.donut {
  overflow: visible;
}

/* The ring turns into place and the segments fade in when the month appears. */
@starting-style {
  .donut {
    transform: rotate(-150deg) scale(0.9);
    opacity: 0;
  }
}

.donut {
  transition:
    transform 760ms var(--ease-spring),
    opacity 300ms var(--ease-standard);
}

.track {
  fill: none;
  stroke: color-mix(in srgb, var(--c-ink) 6%, transparent);
  stroke-width: 13;
}

.segment {
  fill: none;
  stroke-width: 13;
  cursor: pointer;
  pointer-events: visibleStroke;
  transition:
    opacity 160ms var(--ease-standard),
    stroke-width 200ms var(--ease-standard);
}

.segment.rest {
  cursor: default;
}

.segment.on {
  stroke-width: 16;
}

.segment.dim {
  opacity: 0.3;
}

@media (prefers-reduced-motion: reduce) {
  .donut,
  .segment {
    transition: opacity 160ms var(--ease-standard);
  }
}
</style>
