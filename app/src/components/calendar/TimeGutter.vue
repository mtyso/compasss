<script setup lang="ts">
import { fromMinutes } from '@/lib/calendar'

/** The hours down the left edge of the day and week timelines, each label centred on its line. */
defineProps<{ hourHeight: number }>()
</script>

<template>
  <div class="relative shrink-0" aria-hidden="true">
    <span
      v-for="hour in 23"
      :key="hour"
      class="num absolute right-1.5 -translate-y-1/2 text-micro text-ink-3 md:right-2"
      :style="{ top: `${hour * hourHeight}px` }"
    >
      {{ fromMinutes(hour * 60) }}
    </span>
  </div>
</template>
