<script setup lang="ts">
defineProps<{ title: string; empty?: string }>()
</script>

<template>
  <section class="flex flex-col gap-2">
    <h2 class="px-1 text-sm font-semibold">{{ title }}</h2>
    <slot>
      <p class="rounded-2xl border border-dashed border-line px-4 py-5 text-sm text-ink-3">{{ empty }}</p>
    </slot>
  </section>
</template>
