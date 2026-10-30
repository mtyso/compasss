<script setup lang="ts">
import { Check, Minus } from 'lucide-vue-next'
import type { PrayerState } from '@/lib/prayer'

/**
 * A prayer's state as a small dial mark: prayed is filled with the prayer colour, made up (qaza) is the same
 * check on its soft tint, the one whose time is on is a ring with a beating dot, a missed one is a red ring,
 * the ones ahead are a hairline.
 */
withDefaults(defineProps<{ state: PrayerState; size?: 'sm' | 'md' }>(), { size: 'md' })
</script>

<template>
  <span
    class="relative grid shrink-0 place-items-center rounded-full"
    :class="[
      size === 'sm' ? 'size-7' : 'size-9',
      {
        'disc bg-prayer text-bg': state === 'done',
        'bg-prayer-soft text-prayer ring-1 ring-prayer/35 ring-inset': state === 'qaza',
        'ring-2 ring-prayer ring-inset': state === 'now',
        'text-danger ring-[1.5px] ring-danger/70 ring-inset': state === 'missed',
        'ring-1 ring-ink/15 ring-inset': state === 'later' || state === 'skipped',
      },
    ]"
    aria-hidden="true"
  >
    <Check v-if="state === 'done' || state === 'qaza'" :class="size === 'sm' ? 'size-4' : 'size-5'" :stroke-width="2.6" />
    <Minus v-else-if="state === 'missed'" :class="size === 'sm' ? 'size-3.5' : 'size-4'" :stroke-width="2.6" />
    <span v-else-if="state === 'now'" class="beat size-2 rounded-full bg-prayer" />
  </span>
</template>

<style scoped>
.beat {
  animation: beat 2.4s var(--ease-standard) infinite;
}
@keyframes beat {
  0%,
  100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.45;
    transform: scale(0.7);
  }
}
@media (prefers-reduced-motion: reduce) {
  .beat {
    animation: none;
  }
}
</style>
