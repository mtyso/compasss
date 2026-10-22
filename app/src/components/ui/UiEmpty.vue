<script setup lang="ts">
import type { Component } from 'vue'

/**
 * Empty state: says what will appear here and, when there is one, the action that makes it appear.
 * Quiet on purpose: a soft panel with the module's colour on the icon only. An empty list is an
 * invitation, not an error.
 */
withDefaults(defineProps<{ icon?: Component; title?: string; text: string; tone?: 'neutral' | 'money' | 'habit' | 'task' | 'goal' }>(), {
  icon: undefined,
  title: undefined,
  tone: 'neutral',
})

const tones = {
  neutral: 'text-ink-3',
  money: 'text-money',
  habit: 'text-habit',
  task: 'text-task',
  goal: 'text-goal',
} as const
</script>

<template>
  <div class="flex items-start gap-3 rounded-card bg-surface-2 px-4 py-3.5">
    <component :is="icon" v-if="icon" class="mt-px size-5 shrink-0" :class="tones[tone]" :stroke-width="2" />
    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
      <p v-if="title" class="font-semibold">{{ title }}</p>
      <p class="text-[0.9375rem] text-ink-2">{{ text }}</p>
      <div v-if="$slots.default" class="pt-2.5"><slot /></div>
    </div>
  </div>
</template>
