<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Flame } from 'lucide-vue-next'
import HabitButton from '@/components/things/HabitButton.vue'
import { habitIcon } from '@/components/things/habitIcons'
import { amountLabel, scheduleLabel } from '@/lib/habits'
import { CATEGORY_COLORS } from '@/lib/palette'
import type { Habit } from '@/stores/habits'

/**
 * A habit in a list: icon, name, the streak and today's amount, the last seven days as dots
 * (unless `compact`: a ring is a miss a spare day covered, hatching a pause), and the one-tap
 * button on the right.
 */
const props = defineProps<{ habit: Habit; compact?: boolean }>()
const emit = defineEmits<{ tap: []; hold: []; open: [] }>()

const { t } = useI18n()
const color = computed(() => CATEGORY_COLORS[props.habit.color] ?? CATEGORY_COLORS.green)
const streak = computed(() => props.habit.streak.current)
const streakText = computed(() => t(props.habit.streak.unit === 'week' ? 'habits.streakWeeks' : 'habits.streak', streak.value))
const detail = computed(() => {
  if (props.habit.today.state === 'over') return t('habits.over')
  if (props.habit.week_count !== null && 'per_week' in props.habit.schedule) {
    return t('habits.thisWeek', { done: props.habit.week_count, of: props.habit.schedule.per_week })
  }
  return amountLabel(props.habit, t) ?? scheduleLabel(props.habit.schedule, t)
})
const buttonLabel = computed(() =>
  props.habit.type === 'check'
    ? t(props.habit.today.value > 0 ? 'habits.unmark' : 'habits.mark', { name: props.habit.name })
    : t('habits.addOne', { name: props.habit.name }),
)
</script>

<template>
  <div class="flex items-center gap-3 pr-3">
    <button type="button" class="press-soft flex min-h-16 min-w-0 flex-1 items-center gap-3 py-3 pl-4 text-left md:rounded-l-xl" @click="emit('open')">
      <span class="disc vt-shared grid size-10 shrink-0 place-items-center rounded-full text-white" :style="{ backgroundColor: color, '--vt': `habit-${habit.id}` }">
        <component :is="habitIcon(habit.icon)" class="size-5" :stroke-width="2" />
      </span>
      <span class="flex min-w-0 flex-1 flex-col gap-1">
        <span class="truncate font-medium">{{ habit.name }}</span>
        <span class="flex min-w-0 items-center gap-2 text-caption">
          <Transition name="streak" mode="out-in">
            <span v-if="streak > 0" :key="streak" class="flex shrink-0 items-center gap-0.5 font-semibold text-habit">
              <Flame class="size-3.5" :stroke-width="2.4" />{{ streakText }}
            </span>
          </Transition>
          <span class="truncate" :class="habit.today.state === 'over' ? 'font-medium text-danger' : 'text-ink-3'">{{ detail }}</span>
        </span>
        <span v-if="!compact" class="flex gap-1 pt-0.5" aria-hidden="true">
          <span
            v-for="day in habit.days"
            :key="day.date"
            class="h-1.5 w-4 rounded-full"
            :style="day.kept ? { backgroundColor: color } : day.spared ? { boxShadow: `inset 0 0 0 1.25px ${color}` } : undefined"
            :class="day.kept || day.spared ? '' : day.paused ? 'paused' : day.scheduled ? 'bg-ink/12' : 'bg-ink/5'"
          />
        </span>
      </span>
    </button>
    <HabitButton :habit="habit" :label="buttonLabel" @tap="emit('tap')" @hold="emit('hold')" />
  </div>
</template>

<style scoped>
.paused {
  background-image: repeating-linear-gradient(-45deg, color-mix(in srgb, var(--c-ink) 30%, transparent) 0 1px, transparent 1px 3px);
}

.streak-enter-active {
  transition:
    opacity var(--dur-fast) var(--ease-standard),
    transform var(--dur-pop) var(--ease-pop);
}
.streak-leave-active {
  transition: opacity 90ms var(--ease-exit);
}
.streak-enter-from {
  opacity: 0;
  transform: translateY(4px) scale(0.9);
}
.streak-leave-to {
  opacity: 0;
}
</style>
