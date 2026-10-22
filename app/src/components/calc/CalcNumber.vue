<script setup lang="ts">
import { haptics } from '@/lib/haptics'

/**
 * A plain number for a calculator (a rate, a number of months) with its unit after it, and optional
 * presets as chips below: a tap picks one, typing gives any other value. Empty means not given.
 */
withDefaults(
  defineProps<{ label: string; suffix?: string; presets?: number[]; presetLabel?: (value: number) => string; placeholder?: string; hint?: string; size?: 'md' | 'sm' }>(),
  { suffix: undefined, presets: () => [], presetLabel: (value: number) => String(value), placeholder: '0', hint: undefined, size: 'md' },
)
const model = defineModel<number | null>({ required: true })

function onInput(event: Event): void {
  const raw = (event.target as HTMLInputElement).value.replace(',', '.').trim()
  const value = Number(raw)
  model.value = raw === '' || !Number.isFinite(value) ? null : Math.max(0, value)
}

function pick(value: number): void {
  haptics.selection()
  model.value = value
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <label class="flex flex-col gap-2">
      <span class="field-label">{{ label }}</span>
      <span class="field flex items-baseline gap-2 focus-within:shadow-[inset_0_0_0_1.5px_var(--c-ink-3)]" :class="{ '!px-3 !py-2.5': size === 'sm' }">
        <input
          :value="model ?? ''"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          :placeholder="placeholder"
          class="num w-full min-w-0 flex-1 bg-transparent font-semibold outline-none placeholder:font-normal placeholder:text-ink-3"
          :class="size === 'sm' ? 'text-base' : 'text-lg'"
          @input="onInput"
        />
        <span v-if="suffix" class="shrink-0 font-medium text-ink-3" :class="size === 'sm' ? 'text-caption' : 'text-sm'">{{ suffix }}</span>
      </span>
    </label>
    <div v-if="presets.length" class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 py-0.5">
      <button v-for="value in presets" :key="value" type="button" class="chip num" :class="{ 'chip-on': model === value }" :aria-pressed="model === value" @click="pick(value)">
        {{ presetLabel(value) }}
      </button>
    </div>
    <p v-if="hint" class="text-caption text-ink-3">{{ hint }}</p>
  </div>
</template>
