<script setup lang="ts" generic="T extends string | number">
import { computed, ref } from 'vue'
import { haptics } from '@/lib/haptics'

/**
 * Segmented control: one choice out of two to four. The thumb slides to the chosen segment on a
 * spring. Arrow keys move the choice, as in a native radio group.
 */
const props = withDefaults(
  defineProps<{ options: { value: T; label: string }[]; label?: string; size?: 'sm' | 'md' }>(),
  { label: undefined, size: 'md' },
)
const model = defineModel<T>({ required: true })

const buttons = ref<HTMLButtonElement[]>([])
const index = computed(() => Math.max(0, props.options.findIndex((o) => o.value === model.value)))

function choose(value: T): void {
  if (value === model.value) return
  model.value = value
  haptics.selection()
}

function onKey(event: KeyboardEvent): void {
  const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0
  if (!step) return
  event.preventDefault()
  const next = (index.value + step + props.options.length) % props.options.length
  choose(props.options[next]!.value)
  buttons.value[next]?.focus()
}
</script>

<template>
  <div
    role="radiogroup"
    :aria-label="label"
    class="relative grid rounded-[0.75rem] bg-surface-2 p-1"
    :style="{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }"
    @keydown="onKey"
  >
    <span
      class="thumb pointer-events-none absolute top-1 bottom-1 left-1 rounded-[0.5rem] bg-control shadow-card"
      :style="{ width: `calc((100% - 0.5rem) / ${options.length})`, transform: `translateX(${index * 100}%)` }"
      aria-hidden="true"
    />
    <button
      v-for="(option, i) in options"
      :key="String(option.value)"
      ref="buttons"
      type="button"
      role="radio"
      :aria-checked="option.value === model"
      :tabindex="option.value === model ? 0 : -1"
      class="relative z-10 truncate rounded-[0.5rem] px-2 transition-colors duration-200"
      :class="[size === 'sm' ? 'py-1.5 text-caption' : 'py-2 text-sm', i === index ? 'font-semibold text-ink' : 'font-medium text-ink-2 hover:text-ink']"
      @click="choose(option.value)"
    >
      {{ option.label }}
    </button>
  </div>
</template>

<style scoped>
.thumb {
  transition: transform var(--dur-spring) var(--ease-spring);
}
</style>
