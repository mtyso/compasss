<script setup lang="ts">
import { computed, ref } from 'vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { haptics } from '@/lib/haptics'

export interface ScrubBar {
  key: string
  value: number
  /** The hatched part on top (one-offs), as in the day bars. */
  part?: number
  /** What the readout says while this bar is shown. */
  label: string
  note?: string
  /** The label under the axis; none for a bar between ticks. */
  tick?: string
  /** Lit: the month on screen, the busiest hours. */
  strong?: boolean
  /** Nothing to show (a month before the app): a dot on the baseline that cannot be picked. */
  off?: boolean
}

/**
 * Bars with a readout above them, the instrument the day bars are (DayBars): the readout shows a summary
 * until a finger runs along the bars, the pointer hovers or the arrow keys move; then it shows the bar under
 * it, the digits rolling from one value to the next. Used for months side by side, the week and the day.
 *
 * With `clickable`, a click or Enter on a bar emits `pick` with its key (a month: the screen goes to it).
 */
const props = defineProps<{
  bars: ScrubBar[]
  idle: { label: string; amount: number; note?: string }
  label: string
  clickable?: boolean
  /** Tall bars for a few wide ones (months, weekdays), short for many thin ones (hours). */
  size?: 'md' | 'sm'
}>()
const emit = defineEmits<{ pick: [key: string] }>()

const top = computed(() => Math.max(1, ...props.bars.map((bar) => bar.value)))
const picked = ref<number | null>(null)
const shown = computed(() => (picked.value === null ? null : (props.bars[picked.value] ?? null)))

function pick(index: number | null, feel = false): void {
  if (index !== null && (index < 0 || index >= props.bars.length || props.bars[index]!.off)) return
  if (index === picked.value) return
  picked.value = index
  if (feel && index !== null) haptics.selection()
}

const plot = ref<HTMLElement | null>(null)
let pressed = false
let downAt = -1

function indexAt(event: PointerEvent): number {
  const box = plot.value!.getBoundingClientRect()
  const share = Math.min(0.999, Math.max(0, (event.clientX - box.left) / box.width))
  return Math.floor(share * props.bars.length)
}

function onDown(event: PointerEvent): void {
  pressed = true
  downAt = indexAt(event)
  pick(downAt, true)
}

function onMove(event: PointerEvent): void {
  if (event.pointerType === 'mouse' || pressed) pick(indexAt(event), event.pointerType !== 'mouse')
}

function onUp(event: PointerEvent): void {
  pressed = false
  const index = indexAt(event)
  if (props.clickable && index === downAt && !props.bars[index]?.off) emit('pick', props.bars[index]!.key)
  downAt = -1
  if (event.pointerType !== 'mouse') pick(null)
}

function onLeave(event: PointerEvent): void {
  pressed = false
  if (event.pointerType === 'mouse') pick(null)
}

function onKey(event: KeyboardEvent): void {
  const on = props.bars.map((bar, index) => (bar.off ? -1 : index)).filter((index) => index >= 0)
  if (!on.length) return
  const at = picked.value === null ? -1 : on.indexOf(picked.value)
  if (event.key === 'Escape') pick(null)
  else if (event.key === 'ArrowLeft') pick(on[Math.max(0, at < 0 ? on.length - 1 : at - 1)]!)
  else if (event.key === 'ArrowRight') pick(on[Math.min(on.length - 1, at + 1)]!)
  else if (event.key === 'Home') pick(on[0]!)
  else if (event.key === 'End') pick(on[on.length - 1]!)
  else if ((event.key === 'Enter' || event.key === ' ') && props.clickable && picked.value !== null) emit('pick', props.bars[picked.value]!.key)
  else return
  event.preventDefault()
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex min-h-[4.25rem] flex-col gap-1" aria-live="polite">
      <span class="truncate text-caption text-ink-3">{{ shown?.label ?? idle.label }}</span>
      <MoneyAmount :amount="shown ? shown.value : idle.amount" animate class="text-[1.5rem] leading-none font-semibold tracking-[-0.02em]" />
      <span class="min-h-[1.1em] truncate text-caption text-ink-3">{{ shown ? shown.note : idle.note }}</span>
    </div>

    <div class="flex flex-col gap-2">
      <div
        ref="plot"
        class="plot relative touch-pan-y outline-none select-none"
        :class="[size === 'sm' ? 'h-20 md:h-24' : 'h-24 md:h-28', { picking: picked !== null, clickable }]"
        tabindex="0"
        role="group"
        :aria-label="label"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="pressed = false"
        @pointerleave="onLeave"
        @keydown="onKey"
        @blur="pick(null)"
      >
        <div class="absolute inset-0 flex items-end" :class="size === 'sm' ? 'gap-[2px]' : 'gap-2'" aria-hidden="true">
          <span v-for="(bar, index) in bars" :key="bar.key" class="slot relative flex h-full flex-1 items-end justify-center" :class="{ on: picked === index }">
            <span v-if="bar.off" class="off" />
            <span v-else-if="!bar.value" class="stub" />
            <span
              v-else
              class="bar relative w-full overflow-hidden"
              :class="[size === 'sm' ? 'max-w-3' : 'max-w-10', { strong: bar.strong }]"
              :style="{ height: `${Math.max((bar.value / top) * 100, 2.5)}%`, transitionDelay: `${index * (size === 'sm' ? 12 : 40)}ms` }"
            >
              <span v-if="bar.part" class="part absolute inset-x-0 top-0" :style="{ height: `${Math.min(1, bar.part / bar.value) * 100}%` }" />
            </span>
          </span>
        </div>
      </div>

      <div class="flex text-micro text-ink-3" :class="size === 'sm' ? 'gap-[2px]' : 'gap-2'" aria-hidden="true">
        <span
          v-for="bar in bars"
          :key="bar.key"
          class="num flex-1 text-center whitespace-nowrap"
          :class="{ 'font-semibold text-ink': bar.strong, 'opacity-50': bar.off, 'overflow-visible': size === 'sm' }"
        >
          {{ bar.tick ?? '' }}
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

.strong {
  --bar: var(--c-ink);
}

/* The one-off part: the same colour, hatched (as in DayBars). */
.part {
  background-color: var(--c-bg);
  background-image: repeating-linear-gradient(-45deg, var(--bar) 0 1.5px, transparent 1.5px 4px);
  box-shadow: inset 0 0 0 1px var(--bar);
  border-radius: inherit;
}

.stub,
.off {
  display: block;
  border-radius: 999px;
  background-color: color-mix(in srgb, var(--c-ink) 12%, transparent);
  transition: opacity 160ms var(--ease-standard);
}

.stub {
  width: 100%;
  max-width: 2.5rem;
  height: 2px;
}

.off {
  width: 3px;
  height: 3px;
}

.picking .slot:not(.on) :is(.bar, .stub) {
  opacity: 0.32;
}

.picking .on .bar {
  --bar: var(--c-ink);
}

.picking .on .stub {
  background-color: var(--c-ink);
}

.clickable {
  cursor: pointer;
}

.plot:focus-visible {
  outline: 2px solid var(--c-focus);
  outline-offset: 4px;
  border-radius: 0.375rem;
}

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
