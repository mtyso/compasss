<script setup lang="ts">
/**
 * One line of a details list on a view (an operation, an account, a payment): what it is on the left,
 * the value on the right, a hairline between lines. Put them in `<dl class="facts">`; the `hint`
 * slot adds a quiet second line under the value.
 */
defineProps<{ label: string }>()
</script>

<template>
  <div class="flex items-baseline justify-between gap-4 py-3">
    <dt class="max-w-[45%] shrink-0 text-ink-2">{{ label }}</dt>
    <dd class="flex min-w-0 flex-col items-end gap-0.5 text-right">
      <span class="font-medium break-words"><slot /></span>
      <span v-if="$slots.hint" class="text-caption text-ink-3"><slot name="hint" /></span>
    </dd>
  </div>
</template>
