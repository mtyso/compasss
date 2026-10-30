<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { MoonStar } from 'lucide-vue-next'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import { showToast } from '@/components/ui/toast'
import { useI18n } from 'vue-i18n'
import { usePrayer } from '@/stores/prayer'
import PrayerMarkSheet from './PrayerMarkSheet.vue'
import PrayerNowCard from './PrayerNowCard.vue'
import PrayerStrip from './PrayerStrip.vue'
import { usePrayerNow } from './usePrayerNow'

/**
 * Prayers on «Сегодня» (docs/PLAN.md, D40): the offer to keep them until it is answered, then the prayer of the
 * moment with «Прочитал» and the day's five. Nothing once the user said no.
 */
const prayer = usePrayer()
const { t } = useI18n()
const failed = ref(false)
const { current, next, marking, markNow, tap, sheetOpen, sheetTarget } = usePrayerNow()

async function load(): Promise<void> {
  if (prayer.on !== true) return
  try {
    await prayer.ensureLoaded()
    failed.value = false
  } catch {
    failed.value = true
  }
}
onMounted(load)
watch(() => prayer.on, load)

const done = computed(() => prayer.overview?.prayers.filter((p) => p.state === 'done' || p.state === 'qaza').length ?? 0)

const answering = ref<boolean | null>(null)
async function answer(on: boolean): Promise<void> {
  answering.value = on
  try {
    await prayer.saveSettings({ prayer_on: on })
  } catch {
    showToast({ message: t('prayer.settings.failed'), tone: 'danger' })
  } finally {
    answering.value = null
  }
}
</script>

<template>
  <section v-if="prayer.on === null" class="flex flex-col gap-3">
    <h2 class="section-title">{{ $t('prayer.title') }}</h2>
    <UiEmpty :icon="MoonStar" tone="prayer" :text="$t('prayer.offerText')">
      <div class="flex flex-wrap gap-2">
        <UiButton size="sm" variant="secondary" :loading="answering === true" @click="answer(true)">{{ $t('prayer.offerOn') }}</UiButton>
        <UiButton size="sm" variant="ghost" :loading="answering === false" @click="answer(false)">{{ $t('prayer.offerNo') }}</UiButton>
      </div>
    </UiEmpty>
  </section>

  <section v-else-if="prayer.on && prayer.overview" class="flex flex-col gap-3">
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="section-title">
        {{ $t('prayer.title') }}
        <span class="num ml-1 font-medium text-ink-3">{{ done }}/5</span>
      </h2>
      <RouterLink :to="{ name: 'prayer' }" class="section-link">{{ $t('prayer.open') }}</RouterLink>
    </div>
    <PrayerNowCard :current="current" :next="next" :marking="marking !== null" @mark="current && markNow(current.date, current.name)" />
    <PrayerStrip :prayers="prayer.overview.prayers" @tap="tap($event, prayer.overview.date)" />
    <PrayerMarkSheet v-model:open="sheetOpen" :target="sheetTarget" />
  </section>
  <div v-else-if="prayer.on && !failed" class="skeleton h-36" aria-hidden="true" />
</template>
