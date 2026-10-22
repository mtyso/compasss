<script setup lang="ts">
import { computed } from 'vue'

/**
 * A spent-of-budget bar. `value` is the share used (1 = all of it, more = over). `marker` is where
 * the bar should be by now (the share of the month gone), drawn as a notch the fill is compared to.
 * Colour comes from `tone`, or from the value: calm under the marker, amber ahead of it, red over.
 * The fill grows with a transform, so it animates without layout work.
 */
const props = withDefaults(
  defineProps<{ value: number; marker?: number | null; tone?: 'auto' | 'neutral' | 'money' | 'habit' | 'task' | 'goal' | 'danger'; size?: 'sm' | 'md' }>(),
  { marker: null, tone: 'auto', size: 'md' },
)

const fill = computed(() => Math.min(Math.max(props.value, 0), 1))
const resolved = computed(() => {
  if (props.tone !== 'auto') return props.tone
  if (props.value > 1) return 'danger'
  if (props.marker !== null && props.value > props.marker + 0.05) return 'habit'
  return 'money'
})
const colors = {
  neutral: 'bg-ink/30',
  money: 'bg-money',
  habit: 'bg-habit',
  task: 'bg-task',
  goal: 'bg-goal',
  danger: 'bg-danger',
} as const
</script>

<template>
  <div class="relative" :class="size === 'sm' ? 'h-1.5' : 'h-2'">
    <div class="absolute inset-0 overflow-hidden rounded-full bg-ink/[0.07]">
      <div
        class="bar h-full w-full origin-left rounded-full"
        :class="colors[resolved]"
        :style="{ transform: `scaleX(${fill})` }"
      />
    </div>
    <span
      v-if="marker !== null"
      class="marker absolute -top-1 -bottom-1 w-0.5 rounded-full bg-ink/40"
      :style="{ left: `calc(${Math.min(Math.max(marker, 0), 1) * 100}% - 1px)` }"
      aria-hidden="true"
    />
  </div>
</template>

<style scoped>
.bar {
  transition:
    transform 700ms var(--ease-spring),
    background-color var(--dur-base) var(--ease-standard);
}

@starting-style {
  .bar {
    transform: scaleX(0);
  }
}
</style>
