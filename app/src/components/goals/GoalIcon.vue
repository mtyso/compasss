<script setup lang="ts">
import { computed } from 'vue'
import type { Goal } from '@/stores/goals'
import { goalIcon } from './goalLook'

/** A goal's glyph on a solid plum circle, the goal colour of the app; a finished goal goes quiet. */
const props = withDefaults(defineProps<{ goal: Pick<Goal, 'icon' | 'type' | 'done_at'>; size?: 'sm' | 'md' | 'lg' }>(), { size: 'md' })

const component = computed(() => goalIcon(props.goal))
const box = computed(() => ({ sm: 'size-9', md: 'size-10', lg: 'size-12' })[props.size])
const glyph = computed(() => ({ sm: 'size-[18px]', md: 'size-5', lg: 'size-6' })[props.size])
</script>

<template>
  <span class="grid shrink-0 place-items-center rounded-full" :class="[box, goal.done_at ? 'bg-ink/10 text-ink-2' : 'bg-goal text-bg']" aria-hidden="true">
    <component :is="component" :class="glyph" :stroke-width="2" />
  </span>
</template>
