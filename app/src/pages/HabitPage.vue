<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Flame, PauseCircle, Pencil } from 'lucide-vue-next'
import HabitButton from '@/components/things/HabitButton.vue'
import HabitSheet from '@/components/things/HabitSheet.vue'
import HabitValueSheet from '@/components/things/HabitValueSheet.vue'
import HabitYear from '@/components/things/HabitYear.vue'
import StreakScale from '@/components/things/StreakScale.vue'
import { habitIcon } from '@/components/things/habitIcons'
import { useThingActions } from '@/components/things/useThingActions'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import RollingDigits from '@/components/ui/RollingDigits.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import { formatDayMonth } from '@/lib/format'
import { amountLabel, scheduleLabel, SPARE_RULE } from '@/lib/habits'
import { CATEGORY_COLORS } from '@/lib/palette'
import { useHabits, type HabitHistory } from '@/stores/habits'
import { useSettings } from '@/stores/settings'

/**
 * A habit's own screen (docs/PLAN.md, D37): the streak as a counter with the record and the spare
 * days, the milestone scale, the year by days, and the numbers: how often it was kept lately, how
 * many days in all, and what the money it saved means for the main goal.
 */
const route = useRoute()
const router = useRouter()
const habits = useHabits()
const settings = useSettings()
const { t } = useI18n()
const { logHabit } = useThingActions()

const id = computed(() => Number(route.params.id))
const history = ref<HabitHistory | null>(null)
const failed = ref(false)

async function load(): Promise<void> {
  try {
    const [result] = await Promise.all([habits.history(id.value), habits.loaded ? null : habits.load(), habits.loadPauses()])
    history.value = result
    failed.value = false
  } catch {
    failed.value = true
  }
}
onMounted(load)

// The store's copy changes with each tap; the history follows it. Gone from the store = deleted.
const habit = computed(() => habits.habits.find((h) => h.id === id.value) ?? history.value?.habit ?? null)
watch(
  () => {
    const h = habits.habits.find((x) => x.id === id.value)
    return h ? JSON.stringify([h.today, h.schedule, h.target, h.type, h.saves_uzs, h.archived]) : null
  },
  (now, before) => {
    if (now === null && before !== null && habits.loaded) void router.replace({ name: 'tasks', query: { tab: 'habits' } })
    else if (now !== null && before !== undefined && before !== null) void load()
  },
)

const color = computed(() => CATEGORY_COLORS[habit.value?.color ?? 'green'] ?? CATEGORY_COLORS.green)
const streak = computed(() => habit.value?.streak ?? null)
const weekly = computed(() => streak.value?.unit === 'week')
const stats = computed(() => history.value?.stats ?? null)

const detail = computed(() => {
  const h = habit.value
  if (!h) return ''
  const parts = [scheduleLabel(h.schedule, t)]
  const amount = amountLabel(h, t)
  if (amount) parts.push(amount)
  return parts.join(' · ')
})

const buttonLabel = computed(() => {
  const h = habit.value
  if (!h) return ''
  return h.type === 'check' ? t(h.today.value > 0 ? 'habits.unmark' : 'habits.mark', { name: h.name }) : t('habits.addOne', { name: h.name })
})

const spareText = computed(() => {
  const s = streak.value
  if (!s) return ''
  if (s.spare > 0) return t(weekly.value ? 'habits.spareWeeks' : 'habits.spareDays', { n: s.spare }, s.spare)
  return t(weekly.value ? 'habits.spareHintWeeks' : 'habits.spareHintDays')
})
const spareMax = computed(() => SPARE_RULE[streak.value?.unit ?? 'day'].max)

const pauseText = computed(() => (habits.activePause ? t('habits.pause.active', { date: formatDayMonth(habits.activePause.ends_on, settings.locale) }) : null))

const editOpen = ref(false)
const valueOpen = ref(false)

function tap(): void {
  if (habit.value) void logHabit(habit.value)
}

function hold(): void {
  if (habit.value?.type === 'check') editOpen.value = true
  else valueOpen.value = true
}

function saveValue(value: number): void {
  if (habit.value) void logHabit(habit.value, value)
}
</script>

<template>
  <div class="page">
    <ScreenHeader :title="habit?.name ?? $t('habits.title')" back="/tasks">
      <template #trailing>
        <UiIconButton v-if="habit" :label="$t('habits.edit')" @click="editOpen = true">
          <Pencil class="size-5" :stroke-width="2" />
        </UiIconButton>
      </template>
    </ScreenHeader>

    <div v-if="!history && !failed" class="flex flex-col gap-4" aria-hidden="true">
      <div class="skeleton h-12 w-64" />
      <div class="skeleton h-20 w-full rounded-card" />
      <div class="skeleton h-32 w-full rounded-card" />
    </div>
    <p v-else-if="failed && !history" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="habit && streak && stats">
      <!-- What it is, and today's tap. -->
      <section class="flex items-center gap-4">
        <span class="disc vt-shared grid size-14 shrink-0 place-items-center rounded-full text-white" :style="{ backgroundColor: color, '--vt': `habit-${habit.id}` }">
          <component :is="habitIcon(habit.icon)" class="size-7" :stroke-width="2" />
        </span>
        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
          <span class="truncate text-[0.9375rem] text-ink-2">{{ detail }}</span>
          <span v-if="pauseText" class="flex items-center gap-1 text-caption font-medium text-ink-3"><PauseCircle class="size-3.5" :stroke-width="2.2" />{{ pauseText }}</span>
        </div>
        <HabitButton :habit="habit" :label="buttonLabel" @tap="tap" @hold="hold" />
      </section>

      <!-- The streak as a counter, the record and the spares. -->
      <section class="flex flex-col gap-4">
        <div class="flex items-end gap-4">
          <span class="flex items-center gap-1 text-habit">
            <Flame class="size-8 shrink-0" :stroke-width="2.2" aria-hidden="true" />
            <RollingDigits :text="String(streak.current)" class="text-[3.5rem] leading-none font-semibold tracking-[-0.04em]" />
          </span>
          <div class="flex min-w-0 flex-col gap-0.5 pb-1">
            <span class="font-semibold">{{ $t(weekly ? 'habits.weeksInRow' : 'habits.daysInRow', streak.current) }}</span>
            <span class="num text-caption text-ink-2">{{ $t('habits.record', { n: streak.best }) }}</span>
          </div>
        </div>
        <p class="flex items-center gap-2 text-caption text-ink-2">
          <span class="flex gap-1" aria-hidden="true">
            <span
              v-for="n in spareMax"
              :key="n"
              class="size-2.5 rounded-full"
              :style="n <= streak.spare ? { backgroundColor: 'var(--c-habit)' } : { boxShadow: 'inset 0 0 0 1.5px color-mix(in srgb, var(--c-ink) 25%, transparent)' }"
            />
          </span>
          {{ spareText }}
        </p>
        <StreakScale :streak="streak" />
      </section>

      <!-- What the money it saved means for the main goal. -->
      <section v-if="stats.streak_saved_uzs" class="card flex flex-col gap-1 p-4" data-panel>
        <span class="text-caption text-ink-2">{{ $t('habits.savedStreak') }}</span>
        <MoneyAmount :amount="stats.streak_saved_uzs" currency="UZS" animate class="text-headline font-semibold" />
        <span v-if="history?.goal && history.goal.streak_days > 0" class="text-caption text-ink-2">
          = {{ $t('habits.goalDays', { n: history.goal.streak_days, goal: history.goal.title }, history.goal.streak_days) }}
        </span>
      </section>

      <!-- The year, day by day. -->
      <section class="flex flex-col gap-3">
        <h2 class="section-title">{{ $t('habits.year') }}</h2>
        <HabitYear :days="history!.days" :color="color" :type="habit.type" :target="habit.target" :unit="habit.unit" />
      </section>

      <dl class="grid grid-cols-3 gap-4 rounded-card bg-surface-2 px-4 py-3">
        <div class="flex min-w-0 flex-col gap-0.5">
          <dd class="num text-headline font-semibold">{{ stats.rate === null ? '—' : `${Math.round(stats.rate * 100)}%` }}</dd>
          <dt class="text-caption text-ink-3">{{ $t(stats.rate_over === '4w' ? 'habits.rate4w' : 'habits.rate30') }}</dt>
        </div>
        <div class="flex min-w-0 flex-col gap-0.5">
          <dd class="num text-headline font-semibold">{{ stats.kept_days }}</dd>
          <dt class="text-caption text-ink-3">{{ $t('habits.keptDays', stats.kept_days) }}</dt>
        </div>
        <div v-if="stats.saved_uzs" class="flex min-w-0 flex-col gap-0.5">
          <dd class="truncate text-headline font-semibold"><MoneyAmount :amount="stats.saved_uzs" currency="UZS" /></dd>
          <dt class="text-caption text-ink-3">{{ $t('habits.savedTotal') }}</dt>
        </div>
        <div v-else-if="stats.total_value !== null" class="flex min-w-0 flex-col gap-0.5">
          <dd class="num truncate text-headline font-semibold">{{ stats.total_value.toLocaleString('ru-RU') }}</dd>
          <dt class="truncate text-caption text-ink-3">{{ $t('habits.totalValue') }}<template v-if="habit.unit"> · {{ habit.unit }}</template></dt>
        </div>
      </dl>
    </template>

    <HabitSheet v-model:open="editOpen" :habit="habit" />
    <HabitValueSheet v-model:open="valueOpen" :habit="habit" @save="saveValue" />
  </div>
</template>
