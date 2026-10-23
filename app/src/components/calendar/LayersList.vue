<script setup lang="ts">
import { Check, ChevronRight, Clock } from 'lucide-vue-next'
import { eventFill } from '@/lib/calendar'
import { haptics } from '@/lib/haptics'
import { CATEGORY_COLORS } from '@/lib/palette'
import { LAYERS, useCalendar, type Layer } from '@/stores/calendar'

/**
 * «Что показывать»: a check for each layer of the calendar in the colour its things have, then the
 * way to «Распорядок дня». The side panel shows it `dense`; the sheet below 80rem with taller rows.
 */
withDefaults(defineProps<{ dense?: boolean }>(), { dense: false })
const emit = defineEmits<{ navigate: [] }>()

const calendar = useCalendar()

/** The swatch of a layer, and its tick: white on the fixed colours, the page colour on the theme's own. */
const SWATCH: Record<Layer, { fill: string; tick: string }> = {
  events: { fill: eventFill('indigo'), tick: '#fff' },
  routine: { fill: CATEGORY_COLORS.slate!, tick: '#fff' },
  tasks: { fill: 'var(--c-task)', tick: 'var(--c-bg)' },
  payments: { fill: 'var(--c-ink-2)', tick: 'var(--c-bg)' },
  income: { fill: 'var(--c-money)', tick: 'var(--c-bg)' },
  goals: { fill: 'var(--c-goal)', tick: 'var(--c-bg)' },
  debts: { fill: 'var(--c-ink-3)', tick: 'var(--c-bg)' },
  habits: { fill: 'var(--c-habit)', tick: 'var(--c-bg)' },
}

function toggle(layer: Layer): void {
  haptics.selection()
  calendar.toggleLayer(layer)
}
</script>

<template>
  <div class="flex flex-col" :class="dense ? 'gap-0.5' : 'group'">
    <button
      v-for="layer in LAYERS"
      :key="layer"
      type="button"
      role="checkbox"
      :aria-checked="calendar.layers.has(layer)"
      class="press-soft flex w-full items-center gap-3 text-left"
      :class="dense ? 'h-9 rounded-[0.625rem] px-2 text-sm' : 'min-h-12 px-4 py-2.5'"
      @click="toggle(layer)"
    >
      <span
        class="swatch grid size-5 shrink-0 place-items-center rounded-[0.3125rem]"
        :class="{ 'is-on': calendar.layers.has(layer) }"
        :style="{ '--sw': SWATCH[layer].fill, color: SWATCH[layer].tick }"
        aria-hidden="true"
      >
        <Check class="tick size-3.5" :stroke-width="3.2" />
      </span>
      <span class="min-w-0 flex-1 truncate" :class="calendar.layers.has(layer) ? 'text-ink' : 'text-ink-2'">{{ $t(`calendar.layerNames.${layer}`) }}</span>
    </button>

    <RouterLink
      :to="{ name: 'routine' }"
      class="press-soft flex w-full items-center gap-3"
      :class="dense ? 'mt-3 h-10 rounded-[0.625rem] px-2 text-sm font-medium' : 'min-h-14 px-4 py-3 font-medium'"
      @click="emit('navigate')"
    >
      <Clock class="size-5 shrink-0 text-ink-2" :stroke-width="2" aria-hidden="true" />
      <span class="min-w-0 flex-1 truncate">{{ $t('calendar.routineLink') }}</span>
      <ChevronRight class="-mr-1 size-[18px] shrink-0 text-ink-3" :stroke-width="2.2" aria-hidden="true" />
    </RouterLink>
  </div>
</template>

<style scoped>
.swatch {
  box-shadow: inset 0 0 0 1.75px var(--sw);
  transition:
    background-color var(--dur-fast) var(--ease-standard),
    box-shadow var(--dur-fast) var(--ease-standard);
}

.swatch.is-on {
  background-color: var(--sw);
}

.tick {
  opacity: 0;
  transform: scale(0.6);
  transition:
    opacity var(--dur-fast) var(--ease-standard),
    transform var(--dur-pop) var(--ease-pop);
}

.is-on .tick {
  opacity: 1;
  transform: none;
}

@media (prefers-reduced-motion: reduce) {
  .tick {
    transform: none;
  }
}
</style>
