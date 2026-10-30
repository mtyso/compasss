<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UiSpinner from '@/components/ui/UiSpinner.vue'
import type { PrayerName } from '@/lib/prayer'

/**
 * The prayer of the moment: the one whose time is on, with the time left, a bar of how much of it has gone and
 * «Прочитал»; once it is marked, the next one and when it starts.
 */
const props = defineProps<{
  current: { name: PrayerName; end: string; leftText: string; share: number | null } | null
  next: { name: PrayerName; start: string; leftText: string; tomorrow: boolean } | null
  marking?: boolean
}>()
defineEmits<{ mark: [] }>()

const { t } = useI18n()

const line = computed(() => {
  if (props.current) return t('prayer.nowLine', { time: props.current.end, left: props.current.leftText })
  if (!props.next) return ''
  return props.next.tomorrow ? t('prayer.nextTomorrow', { time: props.next.start }) : t('prayer.nextLine', { time: props.next.start, left: props.next.leftText })
})
</script>

<template>
  <div v-if="current || next" class="card flex flex-col gap-3 px-4 py-3.5" data-panel>
    <div class="flex items-center gap-3">
      <div class="flex min-w-0 flex-1 flex-col gap-0.5">
        <span class="text-caption" :class="current ? 'font-medium text-prayer' : 'text-ink-2'">{{ current ? $t('prayer.now') : $t('prayer.next') }}</span>
        <span class="text-lg leading-tight font-semibold">{{ $t(`prayer.names.${(current ?? next)!.name}`) }}</span>
        <span class="num text-caption text-ink-2">{{ line }}</span>
      </div>
      <button
        v-if="current"
        type="button"
        class="lit press relative inline-flex h-9 shrink-0 items-center rounded-[0.625rem] bg-prayer px-3.5 text-sm font-semibold text-bg disabled:opacity-60"
        :disabled="marking"
        @click="$emit('mark')"
      >
        <span :class="{ 'opacity-0': marking }">{{ $t('prayer.prayed') }}</span>
        <span v-if="marking" class="absolute inset-0 grid place-items-center"><UiSpinner :size="16" /></span>
      </button>
    </div>
    <div v-if="current && current.share !== null" class="h-1 overflow-hidden rounded-full bg-ink/10" aria-hidden="true">
      <span class="block h-full rounded-full bg-prayer transition-[width] duration-500" :style="{ width: `${current.share * 100}%` }" />
    </div>
  </div>
</template>
