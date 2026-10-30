<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { milestones, scaleShare } from '@/lib/habits'
import type { HabitStreak } from '@/stores/habits'

/**
 * A streak's milestones as a scale, the way a dial has its divisions: 0 · 7 · 14 · 30 … 365 stand
 * evenly apart, the run is filled up to its place, and the line above says how far the next mark is.
 * In the habit colour, or the prayer colour on «Намаз».
 */
const props = withDefaults(defineProps<{ streak: HabitStreak; tone?: 'habit' | 'prayer' }>(), { tone: 'habit' })
const fill = computed(() => (props.tone === 'prayer' ? 'bg-prayer' : 'bg-habit'))
const { t } = useI18n()

const marks = computed(() => [0, ...milestones(props.streak.unit)])
const share = computed(() => scaleShare(props.streak.current, milestones(props.streak.unit)))
const caption = computed(() =>
  props.streak.next === null
    ? t('habits.allMilestones')
    : t('habits.toNext', { next: props.streak.next, left: props.streak.next - props.streak.current }),
)
// A label at either end is pulled inside the scale instead of hanging over its edge.
const at = (i: number) => i / (marks.value.length - 1)
const anchor = (share: number) => ({ left: `${share * 100}%`, transform: `translateX(-${share * 100}%)` })
</script>

<template>
  <div class="flex flex-col gap-1.5" role="img" :aria-label="`${$t('habits.milestones')}: ${caption}`">
    <div class="relative h-5">
      <span class="absolute top-0 text-caption font-medium whitespace-nowrap text-ink-2" :style="anchor(share)">{{ caption }}</span>
    </div>
    <div class="relative h-3">
      <span class="absolute inset-x-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-ink/10" />
      <span class="fill absolute top-1/2 left-0 h-1 -translate-y-1/2 rounded-full" :class="fill" :style="{ width: `${share * 100}%` }" />
      <span
        v-for="(mark, i) in marks"
        :key="mark"
        class="absolute top-0 h-3 w-px -translate-x-1/2"
        :class="streak.current >= mark ? fill : 'bg-ink/25'"
        :style="{ left: `${at(i) * 100}%` }"
      />
      <span class="marker absolute top-1/2 size-3 -translate-1/2 rounded-full ring-3 ring-bg" :class="fill" :style="{ left: `${share * 100}%` }" />
    </div>
    <div class="relative h-4">
      <span
        v-for="(mark, i) in marks"
        :key="mark"
        class="num absolute text-micro"
        :class="streak.current >= mark ? 'text-ink-2' : 'text-ink-3'"
        :style="anchor(at(i))"
      >
        {{ mark }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.fill,
.marker {
  transition:
    width var(--dur-spring) var(--ease-spring),
    left var(--dur-spring) var(--ease-spring);
}
</style>
