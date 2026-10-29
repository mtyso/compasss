<script setup lang="ts">
/**
 * Spare days as dots, the way a gauge shows spare capacity: a filled dot for each spare held, an empty
 * ring for each that can still be earned (docs/PLAN.md, D37).
 */
defineProps<{ spare: number; max: number; color: string }>()
</script>

<template>
  <span class="flex gap-1" aria-hidden="true">
    <span
      v-for="n in max"
      :key="n"
      class="size-2.5 rounded-full"
      :style="n <= spare ? { backgroundColor: color } : { boxShadow: 'inset 0 0 0 1.5px color-mix(in srgb, var(--c-ink) 25%, transparent)' }"
    />
  </span>
</template>
