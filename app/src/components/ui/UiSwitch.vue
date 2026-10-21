<script setup lang="ts">
import { useId } from 'vue'
import { haptics } from '@/lib/haptics'

/**
 * A setting that is on or off, as a row: title and hint on the left, the switch on the right.
 * The whole row toggles it.
 */
withDefaults(defineProps<{ title: string; hint?: string; disabled?: boolean }>(), { hint: undefined, disabled: false })
const model = defineModel<boolean>({ required: true })
const id = useId()

function toggle(): void {
  model.value = !model.value
  haptics.selection()
}
</script>

<template>
  <div
    class="press-soft flex items-start gap-3 px-4 py-3.5"
    :class="disabled ? 'pointer-events-none opacity-40' : 'cursor-pointer'"
    @click="!disabled && toggle()"
  >
    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
      <span :id="`${id}-title`" class="font-medium">{{ title }}</span>
      <span v-if="hint" :id="`${id}-hint`" class="text-caption text-ink-3">{{ hint }}</span>
    </span>
    <button
      type="button"
      role="switch"
      :aria-checked="model"
      :aria-labelledby="`${id}-title`"
      :aria-describedby="hint ? `${id}-hint` : undefined"
      :disabled="disabled"
      class="track relative mt-0.5 h-7 w-12 shrink-0 rounded-full"
      :class="model ? 'bg-money' : 'bg-ink/15'"
      @click.stop="toggle"
    >
      <span class="knob absolute top-0.5 left-0.5 size-6 rounded-full bg-white shadow-[0_1px_3px_rgb(0_0_0/0.25)]" :class="{ on: model }" />
    </button>
  </div>
</template>

<style scoped>
.track {
  transition: background-color var(--dur-base) var(--ease-standard);
}

.knob {
  transition: transform var(--dur-spring) var(--ease-spring);
}

.knob.on {
  transform: translateX(1.25rem);
}

.track:active .knob {
  transform: scaleX(1.15);
  transform-origin: left;
}

.track:active .knob.on {
  transform: translateX(1.25rem) scaleX(1.15);
  transform-origin: right;
}
</style>
