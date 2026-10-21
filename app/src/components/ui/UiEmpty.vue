<script setup lang="ts">
import type { Component } from 'vue'

/**
 * Empty state: says what will appear here and, when there is one, the action that makes it appear.
 * Quiet on purpose: an empty list is an invitation, not an error.
 */
withDefaults(defineProps<{ icon?: Component; title?: string; text: string; tone?: 'neutral' | 'money' | 'habit' | 'task' | 'goal' }>(), {
  icon: undefined,
  title: undefined,
  tone: 'neutral',
})

const tones = {
  neutral: 'bg-surface-2 text-ink-2',
  money: 'bg-money-soft text-money',
  habit: 'bg-habit-soft text-habit',
  task: 'bg-task-soft text-task',
  goal: 'bg-goal-soft text-goal',
} as const
</script>

<template>
  <div class="flex items-start gap-3.5 rounded-card border border-dashed border-ink/15 px-4 py-4">
    <span v-if="icon" class="grid size-10 shrink-0 place-items-center rounded-2xl" :class="tones[tone]">
      <component :is="icon" class="size-5" :stroke-width="2" />
    </span>
    <div class="flex min-w-0 flex-1 flex-col gap-1 pt-0.5">
      <p v-if="title" class="font-semibold">{{ title }}</p>
      <p class="text-sm text-ink-2">{{ text }}</p>
      <div v-if="$slots.default" class="pt-2"><slot /></div>
    </div>
  </div>
</template>
