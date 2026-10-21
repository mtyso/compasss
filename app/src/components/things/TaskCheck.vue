<script setup lang="ts">
import { ref } from 'vue'
import type { Priority } from '@/stores/tasks'

/**
 * The round checkbox of a task. Its ring shows the priority (orange important, red urgent); ticked,
 * it fills green and the tick draws itself. The pop plays only on a tap, not when a list loads.
 */
defineProps<{ checked: boolean; priority: Priority; label: string }>()
const emit = defineEmits<{ toggle: [] }>()

const tapped = ref(false)
function toggle(): void {
  tapped.value = true
  emit('toggle')
}
</script>

<template>
  <button
    type="button"
    role="checkbox"
    :aria-checked="checked"
    :aria-label="label"
    class="-m-2.5 grid size-11 shrink-0 place-items-center"
    @click.stop="toggle"
  >
    <span
      class="box grid size-[22px] place-items-center rounded-full"
      :class="[checked ? 'is-on text-money' : priority === 2 ? 'text-danger' : priority === 1 ? 'text-habit' : 'text-ink-3', { tapped }]"
    >
      <svg viewBox="0 0 24 24" class="size-[15px]" fill="none" aria-hidden="true">
        <path class="tick" d="M5.5 12.5l4.2 4.2 8.8-9.2" pathLength="1" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </span>
  </button>
</template>

<style scoped>
.box {
  box-shadow: inset 0 0 0 2px currentColor;
  transition:
    background-color var(--dur-fast) var(--ease-standard),
    box-shadow var(--dur-fast) var(--ease-standard),
    color var(--dur-fast) var(--ease-standard);
}

.box.is-on {
  background: currentColor;
}

.box.is-on.tapped {
  animation: check-pop var(--dur-pop) var(--ease-pop);
}

.tick {
  stroke: var(--c-surface);
  stroke-dasharray: 1;
  stroke-dashoffset: 1;
  transition: stroke-dashoffset 260ms var(--ease-standard);
}

.is-on .tick {
  stroke-dashoffset: 0;
  transition-delay: 60ms;
}

button:active .box {
  transform: scale(0.88);
  transition: transform var(--dur-press) var(--ease-standard);
}

@keyframes check-pop {
  0% {
    transform: scale(0.82);
  }
  100% {
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .box.is-on.tapped {
    animation: none;
  }
  .tick {
    transition: none;
  }
}
</style>
