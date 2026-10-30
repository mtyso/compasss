<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Flame, MoonStar, Settings2, Sunrise } from 'lucide-vue-next'
import PrayerMark from '@/components/prayer/PrayerMark.vue'
import PrayerMarkSheet from '@/components/prayer/PrayerMarkSheet.vue'
import PrayerNowCard from '@/components/prayer/PrayerNowCard.vue'
import PrayerSettingsSheet from '@/components/prayer/PrayerSettingsSheet.vue'
import QiblaCompass from '@/components/prayer/QiblaCompass.vue'
import { usePrayerNow } from '@/components/prayer/usePrayerNow'
import HabitYear from '@/components/things/HabitYear.vue'
import StreakScale from '@/components/things/StreakScale.vue'
import RollingDigits from '@/components/ui/RollingDigits.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import { showToast } from '@/components/ui/toast'
import { isoDate, shiftDate } from '@/lib/dates'
import { formatDayMonth } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import type { PrayerName, PrayerState } from '@/lib/prayer'
import { weekdayShort } from '@/lib/tasks'
import { usePrayer } from '@/stores/prayer'
import { useSettings } from '@/stores/settings'

/**
 * «Намаз» (docs/PLAN.md, D40): the prayer of the moment, today's five with their times, the Qibla, the week, the
 * streak of days with all five in their time, the prayers to make up, and the year.
 */
const prayer = usePrayer()
const settings = useSettings()
const { t } = useI18n()
const { current, next, marking, markNow, tap, sheetOpen, sheetTarget } = usePrayerNow()

const failed = ref(false)
const settingsOpen = ref(false)

async function load(): Promise<void> {
  try {
    await prayer.load()
    failed.value = false
    if (prayer.on) void prayer.loadYear().catch(() => undefined)
  } catch {
    failed.value = true
  }
}
onMounted(load)
watch(
  () => prayer.on,
  (on) => on && void prayer.loadYear().catch(() => undefined),
)

const overview = computed(() => prayer.overview)
const tracking = computed(() => prayer.on === true)

/** Today's list: the five, with sunrise between Fajr and Dhuhr, where Fajr's time ends. */
const rows = computed(() => {
  const o = overview.value
  if (!o) return []
  return o.prayers.flatMap((p) => {
    const row = { kind: 'prayer' as const, ...p, until: p.name === 'isha' ? o.times.midnight : null }
    return p.name === 'fajr' ? [row, { kind: 'sunrise' as const, start: o.times.sunrise }] : [row]
  })
})

/** «Фаджр» next to «Бомдод» in Russian, where the Arabic name is also in use; nothing when they are the same. */
function arabic(name: PrayerName): string | null {
  const other = t(`prayer.arabic.${name}`)
  return settings.locale === 'ru' && other !== t(`prayer.names.${name}`) ? other : null
}

const stateText = (state: PrayerState) => t(`prayer.states.${state}`)
const stateTone: Record<PrayerState, string> = {
  done: 'text-prayer',
  qaza: 'text-prayer',
  now: 'text-prayer font-medium',
  later: 'text-ink-3',
  missed: 'text-danger',
  skipped: 'text-ink-3',
}

// The week as a small table: the five down, the days across, today last.
const week = computed(() => overview.value?.week ?? [])
const dot: Record<PrayerState, string> = {
  done: 'bg-prayer',
  qaza: 'bg-prayer/35',
  now: 'ring-2 ring-prayer ring-inset',
  later: 'ring-1 ring-ink/15 ring-inset',
  missed: 'bg-danger/70',
  skipped: 'bg-ink/[0.06]',
}
const names: PrayerName[] = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha']
const stateOf = (day: (typeof week.value)[number], name: PrayerName) => day.prayers.find((p) => p.name === name)?.state ?? 'skipped'

// Missed: newest first, five until asked for all.
const MISSED_SHOWN = 5
const allMissed = ref(false)
const missed = computed(() => {
  const list = overview.value?.missed ?? []
  return allMissed.value ? list : list.slice(0, MISSED_SHOWN)
})
const madeUp = ref<string | null>(null)
function dayLabel(date: string): string {
  if (date === isoDate()) return t('prayer.day')
  if (date === shiftDate(isoDate(), -1)) return t('things.yesterday')
  return formatDayMonth(date, settings.locale)
}
async function makeUp(date: string, name: PrayerName): Promise<void> {
  if (madeUp.value) return
  madeUp.value = `${date} ${name}`
  try {
    await prayer.mark(date, name, 'qaza')
    haptics.notify('success')
  } catch {
    haptics.notify('error')
    showToast({ message: t('prayer.markFailed'), tone: 'danger' })
  } finally {
    madeUp.value = null
  }
}

const streak = computed(() => overview.value?.streak ?? null)
const stats = computed(() => prayer.year?.stats ?? overview.value?.stats ?? null)

const turningOn = ref(false)
async function turnOn(): Promise<void> {
  turningOn.value = true
  try {
    await prayer.saveSettings({ prayer_on: true })
  } catch {
    showToast({ message: t('prayer.settings.failed'), tone: 'danger' })
  } finally {
    turningOn.value = false
  }
}
</script>

<template>
  <div class="page page-wide">
    <ScreenHeader :title="$t('prayer.title')" :overline="overview?.city.name" back="/tasks">
      <template v-if="overview && tracking" #trailing>
        <UiIconButton :label="$t('prayer.settings.title')" @click="settingsOpen = true">
          <Settings2 class="size-5" :stroke-width="2" />
        </UiIconButton>
      </template>
    </ScreenHeader>

    <div v-if="!overview && !failed" class="flex flex-col gap-4" aria-hidden="true">
      <div class="skeleton h-20 w-full rounded-card" />
      <div class="skeleton h-64 w-full rounded-card" />
    </div>
    <p v-else-if="failed && !overview" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <div v-else-if="overview" class="page-columns">
      <div class="page-col">
        <UiEmpty v-if="!tracking" :icon="MoonStar" tone="prayer" :text="$t('prayer.offerText')">
          <UiButton size="sm" variant="secondary" :loading="turningOn" @click="turnOn">{{ $t('prayer.offerOn') }}</UiButton>
        </UiEmpty>
        <PrayerNowCard v-else :current="current" :next="next" :marking="marking !== null" @mark="current && markNow(current.date, current.name)" />

        <!-- Today's times. -->
        <section class="flex flex-col gap-3">
          <h2 class="section-title">{{ $t('prayer.day') }}</h2>
          <div class="group">
            <template v-for="row in rows" :key="row.kind === 'prayer' ? row.name : 'sunrise'">
              <button
                v-if="row.kind === 'prayer'"
                type="button"
                class="press-soft flex min-h-14 w-full items-center gap-3 px-4 py-2.5 text-left"
                :disabled="!tracking || row.state === 'later'"
                @click="tap(row, overview.date)"
              >
                <PrayerMark :state="tracking ? row.state : 'later'" />
                <span class="flex min-w-0 flex-1 flex-col">
                  <span class="font-medium">
                    {{ $t(`prayer.names.${row.name}`) }}
                    <span v-if="arabic(row.name)" class="ml-1 text-caption font-normal text-ink-3">{{ arabic(row.name) }}</span>
                  </span>
                  <span v-if="row.until" class="num text-caption text-ink-3">{{ $t('prayer.ishaUntil', { time: row.until }) }}</span>
                  <span v-else-if="tracking && stateText(row.state)" class="text-caption" :class="stateTone[row.state]">{{ stateText(row.state) }}</span>
                </span>
                <span class="num shrink-0 text-right">
                  <span class="text-[1.0625rem] font-semibold">{{ row.start }}</span>
                  <span class="text-caption text-ink-3">–{{ row.end }}</span>
                </span>
              </button>
              <div v-else class="flex min-h-11 items-center gap-3 px-4 py-2 text-ink-3">
                <span class="grid size-9 shrink-0 place-items-center"><Sunrise class="size-5" :stroke-width="1.9" /></span>
                <span class="flex-1 text-[0.9375rem]">{{ $t('prayer.names.sunrise') }}</span>
                <span class="num text-[0.9375rem]">{{ row.start }}</span>
              </div>
            </template>
          </div>
          <p class="text-caption text-ink-3">{{ $t('prayer.taqvim') }} · {{ overview.city.name }}</p>
        </section>

        <!-- The week. -->
        <section v-if="tracking" class="flex flex-col gap-3 max-xl:order-2">
          <h2 class="section-title">{{ $t('prayer.week') }}</h2>
          <div class="grid grid-cols-[auto_repeat(7,minmax(0,1fr))] items-center gap-x-1 gap-y-2" role="table" :aria-label="$t('prayer.week')">
            <span />
            <span v-for="day in week" :key="day.date" class="flex flex-col items-center text-micro" :class="day.date === overview.date ? 'font-semibold text-ink' : 'text-ink-3'">
              {{ weekdayShort(day.date, t) }}
              <span class="num">{{ Number(day.date.slice(8)) }}</span>
            </span>
            <template v-for="name in names" :key="name">
              <span class="pr-2 text-caption text-ink-2">{{ $t(`prayer.names.${name}`) }}</span>
              <span v-for="day in week" :key="`${name}-${day.date}`" class="grid place-items-center">
                <span class="size-3.5 rounded-full" :class="dot[stateOf(day, name)]" :title="stateText(stateOf(day, name))" />
              </span>
            </template>
          </div>
        </section>

        <!-- The streak. -->
        <section v-if="tracking && streak" class="flex flex-col gap-4 max-xl:order-2">
          <div class="flex items-end gap-4">
            <span class="flex items-center gap-1 text-prayer">
              <Flame class="size-8 shrink-0" :stroke-width="2.2" aria-hidden="true" />
              <RollingDigits :text="String(streak.current)" class="text-[3.5rem] leading-none font-semibold tracking-[-0.04em]" />
            </span>
            <div class="flex min-w-0 flex-col gap-0.5 pb-1">
              <span class="font-semibold">{{ $t('habits.daysInRow', streak.current) }}, {{ $t('prayer.streakLabel') }}</span>
              <span class="num text-caption text-ink-2">{{ $t('habits.record', { n: streak.best }) }}</span>
            </div>
          </div>
          <StreakScale :streak="streak" tone="prayer" />
          <p class="text-caption text-ink-3">{{ $t('prayer.streakHint') }}</p>
        </section>

        <!-- The ones to make up. -->
        <section v-if="tracking" class="flex flex-col gap-3 max-xl:order-2">
          <h2 class="section-title">
            {{ $t('prayer.missed') }}
            <span v-if="overview.missed.length" class="num ml-1 font-medium text-ink-3">{{ $t('prayer.missedCount', { n: overview.missed.length }) }}</span>
          </h2>
          <div v-if="missed.length" class="group">
            <div v-for="m in missed" :key="`${m.date}-${m.name}`" class="flex min-h-13 items-center gap-3 px-4 py-2">
              <PrayerMark state="missed" size="sm" />
              <span class="flex min-w-0 flex-1 flex-col">
                <span class="font-medium">{{ $t(`prayer.names.${m.name}`) }}</span>
                <span class="text-caption text-ink-3">{{ dayLabel(m.date) }}</span>
              </span>
              <UiButton size="sm" variant="secondary" :loading="madeUp === `${m.date} ${m.name}`" @click="makeUp(m.date, m.name)">{{ $t('prayer.madeUp') }}</UiButton>
            </div>
          </div>
          <p v-else class="text-[0.9375rem] text-ink-2">{{ $t('prayer.missedEmpty') }}</p>
          <button v-if="!allMissed && overview.missed.length > MISSED_SHOWN" type="button" class="section-link self-start" @click="allMissed = true">
            {{ $t('prayer.showAll', { n: overview.missed.length }) }}
          </button>
        </section>
      </div>

      <div class="page-col">
        <!-- The Qibla: on a phone right after today's times. -->
        <section class="flex flex-col gap-3 max-xl:order-1">
          <h2 class="section-title">{{ $t('prayer.qibla.title') }}</h2>
          <QiblaCompass :lat="overview.city.lat" :lon="overview.city.lon" :declination="overview.city.declination" />
        </section>

        <section v-if="tracking && prayer.year" class="flex flex-col gap-3 max-xl:order-3">
          <h2 class="section-title">{{ $t('prayer.year') }}</h2>
          <HabitYear :days="prayer.year.days" color="var(--c-prayer)" type="count" :target="5" :unit="null" />
        </section>

        <dl v-if="tracking && stats" class="grid grid-cols-3 gap-4 rounded-card bg-surface-2 px-4 py-3 max-xl:order-3">
          <div class="flex min-w-0 flex-col gap-0.5">
            <dd class="num text-headline font-semibold">{{ stats.rate === null ? '—' : `${Math.round(stats.rate * 100)}%` }}</dd>
            <dt class="text-caption text-ink-3">{{ $t('prayer.rate30') }}</dt>
          </div>
          <div class="flex min-w-0 flex-col gap-0.5">
            <dd class="num text-headline font-semibold">{{ stats.kept_days }}</dd>
            <dt class="text-caption text-ink-3">{{ $t('prayer.keptDays', stats.kept_days) }}</dt>
          </div>
          <div class="flex min-w-0 flex-col gap-0.5">
            <dd class="num text-headline font-semibold">{{ stats.prayed }}</dd>
            <dt class="text-caption text-ink-3">{{ $t('prayer.prayedTotal', stats.prayed) }}</dt>
          </div>
        </dl>
      </div>
    </div>

    <PrayerMarkSheet v-model:open="sheetOpen" :target="sheetTarget" />
    <PrayerSettingsSheet v-model:open="settingsOpen" />
  </div>
</template>
