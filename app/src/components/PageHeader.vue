<script setup lang="ts">
import ScreenHeader from '@/components/ui/ScreenHeader.vue'

// Header of a screen opened from another one (Ещё → Счета): back button, large title that collapses
// into the bar on scroll. The default slot goes next to the large title. New screens can use
// ui/ScreenHeader directly.
withDefaults(defineProps<{ title: string; back?: string }>(), { back: '/more' })
</script>

<template>
  <ScreenHeader :title="title" :back="back">
    <template v-if="$slots.default" #trailing><slot /></template>
  </ScreenHeader>
</template>
