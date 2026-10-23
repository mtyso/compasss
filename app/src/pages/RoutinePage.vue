<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Bell, Clock, Plus } from 'lucide-vue-next'
import RoutineSheet from '@/components/calendar/RoutineSheet.vue'
import { blockSubtitle, blocksOn, dayTotals, EXAMPLE_BLOCKS, newBlockDays, timetable, totalsParts } from '@/components/calendar/routine'
import { routineColor, routineLook } from '@/components/calendar/routineLook'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiRow from '@/components/ui/UiRow.vue'
import { showToast } from '@/components/ui/toast'
import { durationLabel } from '@/lib/calendar'
import { isoDate } from '@/lib/dates'
import { haptics } from '@/lib/haptics'
import { isoWeekday, weekdayNames } from '@/lib/tasks'
import { useCalendar, type RoutineBlock } from '@/stores/calendar'
import { useHabits } from '@/stores/habits'

/**
 * «Распорядок дня»: the usual day by the hour (docs/PLAN.md, D33). One weekday at a time,
 * as a timetable: its blocks in order, the night from the evening before first, and the free time
 * between them; under it how the day adds up. A tap on a block edits it.
 */
const calendar = useCalendar()
const habits = useHabits()
const { t } = useI18n()

const todayWeekday = isoWeekday(isoDate())
const weekday = ref(todayWeekday)
const failed = ref(false)

onMounted(async () => {
  // Habit names for «привычка Спорт»; the timetable does not wait for them.
  void habits.ensureLoaded().catch(() => {})
  try {
    await calendar.ensureRoutine()
    failed.value = false
  } catch {
    failed.value = true
  }
})

const weekdays = computed(() => weekdayNames(t))
const parts = computed(() => blocksOn(calendar.blocks, weekday.value))
const rows = computed(() => timetable(parts.value))
const summary = computed(() => (parts.value.length ? totalsParts(dayTotals(parts.value), t) : []))

function habitName(id: number | null): string | null {
  return id === null ? null : (habits.habits.find((h) => h.id === id)?.name ?? null)
}

function pickWeekday(day: number): void {
  haptics.selection()
  weekday.value = day
}

const sheetOpen = ref(false)
const editing = ref<RoutineBlock | null>(null)
const draft = ref<Partial<RoutineBlock> | null>(null)

function openBlock(block: RoutineBlock | null): void {
  editing.value = block
  draft.value = block ? null : { days: newBlockDays(weekday.value, todayWeekday) }
  sheetOpen.value = true
}

// «Начать с примера»: a usual working week, one block after another so they keep this order.
const seeding = ref(false)
async function seed(): Promise<void> {
  if (seeding.value) return
  seeding.value = true
  try {
    for (const [index, { example, ...block }] of EXAMPLE_BLOCKS.entries()) {
      await calendar.saveBlock({ ...block, title: t(`routine.examples.${example}`), sort: index })
    }
    haptics.notify('success')
  } catch {
    haptics.notify('error')
    showToast({ message: t('routine.failed'), tone: 'danger' })
  } finally {
    seeding.value = false
  }
}
</script>

<template>
  <div class="page">
    <ScreenHeader :title="$t('routine.title')" back="/tasks/calendar">
      <template #trailing>
        <UiIconButton tone="filled" :label="$t('routine.new')" @click="openBlock(null)">
          <Plus class="size-5" :stroke-width="2.2" />
        </UiIconButton>
      </template>
    </ScreenHeader>

    <p class="-mt-3 text-sm text-ink-2">{{ $t('routine.intro') }}</p>

    <p v-if="failed" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="!calendar.routineLoaded">
      <div class="skeleton h-10" />
      <div class="skeleton h-56" />
    </template>

    <UiEmpty v-else-if="!calendar.blocks.length" :icon="Clock" :text="$t('routine.empty')">
      <div class="flex flex-wrap gap-2">
        <UiButton size="sm" :loading="seeding" @click="seed">{{ $t('routine.example') }}</UiButton>
        <UiButton size="sm" variant="secondary" @click="openBlock(null)">{{ $t('routine.addOwn') }}</UiButton>
      </div>
    </UiEmpty>

    <template v-else>
      <div class="grid grid-cols-7 gap-1" role="radiogroup" :aria-label="$t('routine.days')">
        <button
          v-for="(name, index) in weekdays"
          :key="name"
          type="button"
          role="radio"
          class="press rounded-control py-2.5 text-sm font-medium"
          :class="weekday === index + 1 ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2'"
          :aria-checked="weekday === index + 1"
          @click="pickWeekday(index + 1)"
        >
          {{ name }}
        </button>
      </div>

      <section class="flex flex-col gap-2">
        <div v-if="rows.length" class="group">
          <template v-for="row in rows" :key="row.key">
            <UiRow
              v-if="row.type === 'block'"
              :title="row.part.block.title"
              :subtitle="blockSubtitle(row.part, t, habitName(row.part.block.habit_id))"
              :chevron="false"
              @click="openBlock(row.part.block)"
            >
              <template #leading>
                <span class="disc grid size-10 place-items-center rounded-full text-white" :style="{ backgroundColor: routineColor(row.part.block.kind) }">
                  <component :is="routineLook(row.part.block.kind).icon" class="size-5" :stroke-width="2" />
                </span>
              </template>
              <template v-if="row.part.block.remind" #trailing>
                <Bell class="size-4 text-ink-3" :stroke-width="2" :aria-label="$t('routine.remind')" />
              </template>
            </UiRow>
            <div v-else class="flex items-center gap-3 px-4 py-2.5 text-caption text-ink-3">
              <span class="w-10 shrink-0" aria-hidden="true" />
              <span class="num">{{ $t('routine.free', { time: durationLabel(row.endMin - row.startMin, t) }) }}</span>
            </div>
          </template>
        </div>
        <p v-else class="text-sm text-ink-3">{{ $t('routine.dayEmpty') }}</p>

        <p v-if="summary.length" class="num pt-1 text-sm text-ink-2">
          <template v-for="(part, i) in summary" :key="i"><template v-if="i"> · </template><span class="whitespace-nowrap">{{ part }}</span></template>
        </p>
      </section>
    </template>

    <RoutineSheet v-model:open="sheetOpen" :block="editing" :draft="draft" />
  </div>
</template>
