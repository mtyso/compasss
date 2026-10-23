<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Check, Plus } from 'lucide-vue-next'
import { progress } from '@/lib/habits'
import { CATEGORY_COLORS } from '@/lib/palette'
import type { Habit } from '@/stores/habits'

/**
 * The one-tap control of a habit. A check habit is a circle that fills with the habit's colour; a
 * count or limit habit is a ring that fills towards the target, with the number inside, and turns
 * red once a limit is broken. A long press asks for an exact number instead. `sm` is the smaller
 * one the calendar puts in a row of a day.
 */
const props = withDefaults(defineProps<{ habit: Habit; label: string; size?: 'sm' | 'md' }>(), { size: 'md' })
const emit = defineEmits<{ tap: []; hold: [] }>()

const color = computed(() => CATEGORY_COLORS[props.habit.color] ?? CATEGORY_COLORS.green)
const share = computed(() => progress(props.habit))
const done = computed(() => props.habit.today.state === 'done' && (props.habit.type !== 'limit' || props.habit.today.value > 0))
const over = computed(() => props.habit.today.state === 'over')
const RADIUS = 19
const CIRCUMFERENCE = 2 * Math.PI * RADIUS
const dash = computed(() => CIRCUMFERENCE * (1 - Math.min(1, share.value)))

// A bump each time the value changes by a tap.
const bump = ref(false)
watch(
  () => props.habit.today.value,
  (value, previous) => {
    if (value === previous) return
    bump.value = false
    requestAnimationFrame(() => (bump.value = true))
  },
)

let holdTimer: number | undefined
let held = false
function down(): void {
  held = false
  holdTimer = window.setTimeout(() => {
    held = true
    emit('hold')
  }, 480)
}
function cancel(): void {
  window.clearTimeout(holdTimer)
}
function click(): void {
  if (held) return
  emit('tap')
}
onBeforeUnmount(cancel)
</script>

<template>
  <button
    type="button"
    class="habit-button relative grid shrink-0 place-items-center rounded-full"
    :class="[size === 'sm' ? 'size-9' : 'size-12', { 'is-done': done, 'is-over': over, bump }]"
    :style="{ '--hc': color }"
    :aria-label="label"
    :aria-pressed="habit.type === 'check' ? done : undefined"
    @pointerdown="down"
    @pointerup="cancel"
    @pointerleave="cancel"
    @pointercancel="cancel"
    @contextmenu.prevent
    @click="click"
    @animationend="bump = false"
  >
    <template v-if="habit.type === 'check'">
      <span class="fill absolute inset-1 rounded-full" />
      <Check class="tick relative" :class="size === 'sm' ? 'size-4' : 'size-5'" :stroke-width="3" />
    </template>
    <template v-else>
      <svg class="absolute inset-0 -rotate-90" viewBox="0 0 48 48" aria-hidden="true">
        <circle cx="24" cy="24" :r="RADIUS" class="track" fill="none" stroke-width="3.5" />
        <circle
          cx="24"
          cy="24"
          :r="RADIUS"
          class="bar"
          fill="none"
          stroke-width="3.5"
          stroke-linecap="round"
          :stroke-dasharray="CIRCUMFERENCE"
          :stroke-dashoffset="dash"
        />
      </svg>
      <span v-if="habit.today.value > 0" class="value num relative font-bold" :class="size === 'sm' ? 'text-xs' : 'text-sm'">{{ habit.today.value }}</span>
      <Plus v-else class="relative text-ink-3" :class="size === 'sm' ? 'size-4' : 'size-5'" :stroke-width="2.4" />
    </template>
  </button>
</template>

<style scoped>
.habit-button {
  transition: transform var(--dur-spring) var(--ease-spring);
}

.habit-button:active {
  transform: scale(0.9);
  transition-duration: var(--dur-press);
}

.habit-button.bump {
  animation: habit-bump var(--dur-pop) var(--ease-pop);
}

/* Check habits */
.fill {
  box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--hc) 45%, transparent);
  transition:
    background-color var(--dur-fast) var(--ease-standard),
    box-shadow var(--dur-fast) var(--ease-standard);
}

.tick {
  color: color-mix(in srgb, var(--hc) 55%, transparent);
  transform: scale(0.85);
  transition:
    color var(--dur-fast) var(--ease-standard),
    transform var(--dur-pop) var(--ease-pop);
}

.is-done .fill {
  background: var(--hc);
  box-shadow: inset 0 0 0 2px var(--hc);
}

.is-done .tick {
  color: #fff;
  transform: scale(1);
}

/* Count and limit habits */
.track {
  stroke: color-mix(in srgb, var(--hc) 16%, transparent);
}

.bar {
  stroke: var(--hc);
  transition:
    stroke-dashoffset 600ms var(--ease-spring),
    stroke var(--dur-fast) var(--ease-standard);
}

.is-over .bar {
  stroke: var(--c-danger);
}

.value {
  color: var(--c-ink);
}

.is-done .value {
  color: var(--hc);
}

.is-over .value {
  color: var(--c-danger);
}

@keyframes habit-bump {
  0% {
    transform: scale(0.86);
  }
  100% {
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .habit-button.bump {
    animation: none;
  }
}
</style>
