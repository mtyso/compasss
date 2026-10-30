<script setup lang="ts">
import { tapAction, type PrayerName } from '@/lib/prayer'
import type { PrayerDay } from '@/stores/prayer'
import PrayerMark from './PrayerMark.vue'

/** The day's five side by side, as on a dial: the mark, the name and when it starts. A tap marks or asks. */
defineProps<{ prayers: PrayerDay[] }>()
defineEmits<{ tap: [prayer: PrayerDay] }>()

const label = (name: PrayerName) => `prayer.names.${name}`
</script>

<template>
  <div class="grid grid-cols-5 gap-1">
    <button
      v-for="prayer in prayers"
      :key="prayer.name"
      type="button"
      class="press-soft flex min-w-0 flex-col items-center gap-1.5 rounded-xl py-2"
      :aria-disabled="tapAction(prayer.state) === 'none'"
      :aria-label="`${$t(label(prayer.name))} ${prayer.start}${$t(`prayer.states.${prayer.state}`) ? ', ' + $t(`prayer.states.${prayer.state}`) : ''}`"
      @click="tapAction(prayer.state) !== 'none' && $emit('tap', prayer)"
    >
      <PrayerMark :state="prayer.state" size="sm" />
      <span class="flex min-w-0 max-w-full flex-col items-center">
        <span class="max-w-full truncate text-caption font-medium" :class="prayer.state === 'now' ? 'text-prayer' : prayer.state === 'missed' ? 'text-danger' : ''">
          {{ $t(label(prayer.name)) }}
        </span>
        <span class="num text-micro text-ink-3">{{ prayer.start }}</span>
      </span>
    </button>
  </div>
</template>
