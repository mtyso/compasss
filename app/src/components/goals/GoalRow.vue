<script setup lang="ts">
import { computed } from 'vue'
import UiProgress from '@/components/ui/UiProgress.vue'
import { statusTone } from '@/lib/goals'
import type { Goal } from '@/stores/goals'
import GoalIcon from './GoalIcon.vue'
import { useGoalText } from './useGoalText'

/** A goal in a list: its icon, how far along it is, and how it is doing. Opens the goal. */
const props = defineProps<{ goal: Goal; main?: boolean }>()
const text = useGoalText()

const tone = computed(() => statusTone(props.goal.status))
const toneText = { money: 'text-money', habit: 'text-habit', danger: 'text-danger', goal: 'text-ink-3', neutral: 'text-ink-3' } as const
const percent = computed(() => Math.min(100, Math.floor(props.goal.progress * 100)))
</script>

<template>
  <RouterLink :to="{ name: 'goal', params: { id: goal.id } }" class="press-soft flex w-full items-center gap-3 px-4 py-3 text-left">
    <GoalIcon :goal="goal" />
    <span class="flex min-w-0 flex-1 flex-col gap-1.5">
      <span class="flex items-baseline justify-between gap-3">
        <span class="flex min-w-0 items-baseline gap-2">
          <span class="truncate font-medium">{{ goal.title }}</span>
          <span v-if="main" class="shrink-0 text-micro font-semibold tracking-wide text-goal uppercase">{{ $t('goals.main') }}</span>
        </span>
        <span class="num shrink-0 text-caption font-medium text-ink-2">{{ percent }}%</span>
      </span>
      <UiProgress :value="goal.progress" :tone="goal.done_at ? 'neutral' : 'goal'" size="sm" />
      <span class="truncate text-caption" :class="toneText[tone]">{{ text.line(goal) }}</span>
    </span>
  </RouterLink>
</template>
