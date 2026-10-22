<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { haptics } from '@/lib/haptics'
import { useGoals } from '@/stores/goals'
import GoalIcon from './GoalIcon.vue'

/** Which goal a task or a habit works towards, as a row of chips; hidden while there are no goals. */
const model = defineModel<number | null>({ required: true })
const goals = useGoals()

onMounted(() => goals.ensureLoaded().catch(() => undefined))

// A finished goal stays on the list while it is the one already chosen.
const choices = computed(() => goals.goals.filter((g) => !g.done_at || g.id === model.value))

function choose(id: number | null): void {
  haptics.selection()
  model.value = id
}
</script>

<template>
  <section v-if="choices.length" class="flex flex-col gap-2">
    <h3 class="field-label">{{ $t('goals.forGoal') }}</h3>
    <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
      <button type="button" class="chip" :class="{ 'chip-on': model === null }" :aria-pressed="model === null" @click="choose(null)">{{ $t('goals.noGoal') }}</button>
      <button
        v-for="goal in choices"
        :key="goal.id"
        type="button"
        class="chip !py-1.5 !pl-1.5"
        :class="{ 'chip-on': model === goal.id }"
        :aria-pressed="model === goal.id"
        @click="choose(goal.id)"
      >
        <GoalIcon :goal="goal" size="sm" class="!size-7" />
        {{ goal.title }}
      </button>
    </div>
  </section>
</template>
