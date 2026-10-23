<script setup lang="ts">
import { computed } from 'vue'
import type { Goal } from '@/stores/goals'
import { goalIcon } from './goalLook'

/**
 * A goal's glyph on a solid plum circle, the goal colour of the app; a finished goal goes quiet.
 * With `ring` (the share done, 0…1) it becomes a small dial: the circle sits inside a ring that
 * fills clockwise from the top, so a list of goals reads at a glance without a bar under each.
 * `xl` is the dial at the top of a goal's own screen.
 */
const props = withDefaults(
  defineProps<{ goal: Pick<Goal, 'icon' | 'type' | 'done_at'>; size?: 'sm' | 'md' | 'lg' | 'xl'; ring?: number | null }>(),
  { size: 'md', ring: null },
)

const component = computed(() => goalIcon(props.goal))
const box = computed(() => ({ sm: 'size-9', md: 'size-10', lg: 'size-12', xl: 'size-16' })[props.size])
const glyph = computed(() => ({ sm: 'size-[18px]', md: 'size-5', lg: 'size-6', xl: 'size-7' })[props.size])

// The dial is drawn in a 48-unit box whatever its size, so the ring keeps its weight when scaled.
const RADIUS = 22
const CIRCUMFERENCE = 2 * Math.PI * RADIUS
const dash = computed(() => CIRCUMFERENCE * (1 - Math.min(1, Math.max(0, props.ring ?? 0))))
const dial = computed(() => ({ sm: 'size-10', md: 'size-11', lg: 'size-14', xl: 'size-[4.5rem]' })[props.size])
const inner = computed(() => ({ sm: 'size-7', md: 'size-8', lg: 'size-10', xl: 'size-[3.25rem]' })[props.size])
</script>

<template>
  <span v-if="ring !== null" class="goal-dial relative grid shrink-0 place-items-center" :class="[dial, { done: goal.done_at }]" aria-hidden="true">
    <svg class="absolute inset-0 -rotate-90" viewBox="0 0 48 48">
      <circle cx="24" cy="24" :r="RADIUS" class="track" fill="none" stroke-width="3" />
      <circle
        cx="24"
        cy="24"
        :r="RADIUS"
        class="arc"
        fill="none"
        stroke-width="3"
        stroke-linecap="round"
        :stroke-dasharray="CIRCUMFERENCE"
        :style="{ '--dash': dash, '--full': CIRCUMFERENCE }"
      />
    </svg>
    <span class="icon-disc grid place-items-center rounded-full" :class="[inner, goal.done_at ? 'bg-ink/10 text-ink-2' : 'disc bg-goal text-bg']">
      <component :is="component" :class="glyph" :stroke-width="2" />
    </span>
  </span>
  <span v-else class="icon-disc grid shrink-0 place-items-center rounded-full" :class="[box, goal.done_at ? 'bg-ink/10 text-ink-2' : 'disc bg-goal text-bg']" aria-hidden="true">
    <component :is="component" :class="glyph" :stroke-width="2" />
  </span>
</template>

<style scoped>
.track {
  stroke: color-mix(in srgb, var(--c-goal) 16%, transparent);
}

.arc {
  stroke: var(--c-goal);
  stroke-dashoffset: var(--dash);
  transition: stroke-dashoffset 900ms var(--ease-spring);
}

.done .track {
  stroke: color-mix(in srgb, var(--c-ink) 10%, transparent);
}

.done .arc {
  stroke: color-mix(in srgb, var(--c-ink) 35%, transparent);
}

/* The ring fills from empty when it first appears, once, with the screen. */
@starting-style {
  .arc {
    stroke-dashoffset: var(--full);
  }
}
</style>
