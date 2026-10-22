<script setup lang="ts">
import { computed } from 'vue'
import SectionBlock from '@/components/SectionBlock.vue'
import { formatDayHeading } from '@/lib/format'
import { useSettings } from '@/stores/settings'

const settings = useSettings()
const heading = computed(() => formatDayHeading(new Date(), settings.locale))
</script>

<template>
  <div class="flex flex-col gap-5">
    <header class="flex flex-col gap-0.5 px-1">
      <span class="text-sm text-ink-3">{{ heading }}</span>
      <h1 class="font-display text-2xl font-bold">{{ $t('today.title') }}</h1>
    </header>

    <article class="flex flex-col gap-2 rounded-3xl border border-line bg-surface p-5">
      <span class="text-[11px] font-semibold tracking-wider text-ink-3 uppercase">{{ $t('today.limit') }}</span>
      <span class="num font-display text-4xl font-bold text-ink-3">—</span>
      <p class="text-sm text-ink-2">{{ $t('today.limitEmpty') }}</p>
    </article>

    <SectionBlock :title="$t('today.advice')" :empty="$t('today.adviceEmpty')" />
    <SectionBlock :title="$t('today.habits')" :empty="$t('today.habitsEmpty')" />
    <SectionBlock :title="$t('today.tasks')" :empty="$t('today.tasksEmpty')" />
  </div>
</template>
