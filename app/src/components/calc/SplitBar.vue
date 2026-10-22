<script setup lang="ts">
import { computed } from 'vue'

/**
 * Two parts of one sum as a bar, with a legend: what is borrowed against the interest on it, or one's
 * own money against what the deposit adds. The second part grows from the right edge of the first.
 */
const props = defineProps<{ first: number; second: number; firstLabel: string; secondLabel: string; secondTone: 'money' | 'habit' }>()

const share = computed(() => {
  const total = Math.max(0, props.first) + Math.max(0, props.second)
  return total > 0 ? Math.max(0, props.second) / total : 0
})
const percent = computed(() => Math.round(share.value * 100))
</script>

<template>
  <div class="flex flex-col gap-2">
    <div class="flex h-2.5 overflow-hidden rounded-full bg-ink/[0.07]" aria-hidden="true">
      <div class="bar h-full bg-ink/25" :style="{ flexGrow: 1 - share }" />
      <div class="bar h-full" :class="secondTone === 'money' ? 'bg-money' : 'bg-habit'" :style="{ flexGrow: share }" />
    </div>
    <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 text-caption text-ink-3">
      <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-ink/25" />{{ firstLabel }}</span>
      <span class="flex items-center gap-1.5">
        <span class="size-2 rounded-full" :class="secondTone === 'money' ? 'bg-money' : 'bg-habit'" />{{ secondLabel }}
        <span class="num font-medium text-ink-2">{{ percent }}%</span>
      </span>
    </div>
  </div>
</template>

<style scoped>
.bar {
  flex-basis: 0;
  transition: flex-grow var(--dur-spring) var(--ease-spring);
}
</style>
