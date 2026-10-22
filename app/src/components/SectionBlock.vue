<script setup lang="ts">
import type { Component } from 'vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'

// A titled block on a screen. Without content it shows the empty text in the shared empty style.
defineProps<{ title: string; empty?: string; icon?: Component; tone?: 'neutral' | 'money' | 'habit' | 'task' | 'goal' }>()
</script>

<template>
  <section class="flex flex-col gap-3">
    <h2 class="section-title">{{ title }}</h2>
    <slot>
      <UiEmpty :icon="icon" :text="empty ?? ''" :tone="tone" />
    </slot>
  </section>
</template>
