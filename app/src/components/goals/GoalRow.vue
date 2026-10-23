<script setup lang="ts">
import { computed } from 'vue'
import { statusTone } from '@/lib/goals'
import type { Goal } from '@/stores/goals'
import GoalIcon from './GoalIcon.vue'
import { useGoalText } from './useGoalText'

/**
 * A goal in a list: its dial (the icon inside a ring of how far along it is), how it is doing, and
 * the percentage. Opens the goal; the dial flies to the top of the goal's screen.
 */
const props = defineProps<{ goal: Goal; main?: boolean }>()
const text = useGoalText()

const tone = computed(() => statusTone(props.goal.status))
const toneText = { money: 'text-money', habit: 'text-habit', danger: 'text-danger', goal: '', neutral: '' } as const
const forecast = computed(() => text.forecast(props.goal))
const percent = computed(() => Math.min(100, Math.floor(props.goal.progress * 100)))
</script>

<template>
  <RouterLink :to="{ name: 'goal', params: { id: goal.id } }" class="press-soft flex w-full items-center gap-3 px-4 py-3 text-left">
    <GoalIcon :goal="goal" :ring="goal.progress" class="vt-shared -ml-0.5" :style="{ '--vt': `goal-${goal.id}` }" />
    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
      <span class="flex items-baseline gap-2">
        <span class="truncate font-medium">{{ goal.title }}</span>
        <span v-if="main" class="shrink-0 text-caption font-medium text-goal">{{ $t('goals.main') }}</span>
      </span>
      <!-- Only the status word carries colour, so the line does not read as a link. -->
      <span class="truncate text-caption text-ink-2"><span :class="toneText[tone]">{{ text.status(goal) }}</span><template v-if="forecast"> · {{ forecast }}</template></span>
    </span>
    <span class="num shrink-0 self-center text-[0.9375rem] font-semibold" :class="goal.done_at ? 'text-ink-3' : 'text-ink'">{{ percent }}<span class="text-caption font-medium text-ink-3">%</span></span>
  </RouterLink>
</template>
