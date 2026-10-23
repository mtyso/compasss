<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Trash2 } from 'lucide-vue-next'
import { habitIcon } from '@/components/things/habitIcons'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { haptics } from '@/lib/haptics'
import { CATEGORY_COLORS } from '@/lib/palette'
import { weekdayNames } from '@/lib/tasks'
import { ROUTINE_KINDS, useCalendar, type RoutineBlock, type RoutineKind } from '@/stores/calendar'
import { useHabits } from '@/stores/habits'
import { reachesNextDay } from './routine'
import { routineColor, routineLook } from './routineLook'

/**
 * Create or edit a routine block: what it is, its name, from and to (past midnight is fine: sleep
 * 23:00–07:00), the weekdays, a reminder at the start and the habit it shows. `block` edits one;
 * `draft` pre-fills a new one (the weekday picked on the routine page, a time on the calendar).
 */
const props = defineProps<{ block: RoutineBlock | null; draft?: Partial<RoutineBlock> | null }>()
const open = defineModel<boolean>('open', { required: true })

const calendar = useCalendar()
const habits = useHabits()
const { t } = useI18n()

const EVERY_DAY = [1, 2, 3, 4, 5, 6, 7]

const kind = ref<RoutineKind>('other')
const title = ref('')
const start = ref('09:00')
const end = ref('10:00')
const days = ref<number[]>([...EVERY_DAY])
const remind = ref(false)
const habitId = ref<number | null>(null)
const saving = ref(false)
const deleting = ref(false)
const error = ref<string | null>(null)

const weekdays = computed(() => weekdayNames(t))
const presets = computed(() => [
  { days: EVERY_DAY, label: t('habits.schedule.daily') },
  { days: [1, 2, 3, 4, 5], label: t('habits.schedule.weekdays') },
  { days: [6, 7], label: t('habits.schedule.weekends') },
])

watch(open, (value) => {
  if (!value) return
  // The habit chips need the habits; the calendar usually has them already.
  void habits.ensureLoaded().catch(() => {})
  const source = props.block ?? props.draft ?? {}
  error.value = null
  kind.value = source.kind ?? 'other'
  title.value = source.title ?? ''
  start.value = source.start_time ?? '09:00'
  end.value = source.end_time ?? '10:00'
  days.value = source.days?.length ? [...source.days] : [...EVERY_DAY]
  remind.value = source.remind ?? false
  habitId.value = source.habit_id ?? null
})

function pickKind(next: RoutineKind): void {
  haptics.selection()
  // An empty title, or the name the previous kind filled in, becomes the new kind's name.
  if (!title.value.trim() || title.value === t(`routine.kinds.${kind.value}`)) title.value = t(`routine.kinds.${next}`)
  kind.value = next
}

/** A time emptied with the browser's clear button goes back to what it was. */
function setTime(event: Event, which: 'start' | 'end'): void {
  const field = event.target as HTMLInputElement
  const target = which === 'start' ? start : end
  if (field.value) target.value = field.value
  else field.value = target.value
}

const sameTime = computed(() => start.value === end.value)
const overnight = computed(() => reachesNextDay({ start_time: start.value, end_time: end.value }))

function sameDays(a: number[], b: number[]): boolean {
  return a.length === b.length && a.every((d) => b.includes(d))
}

function pickDays(next: number[]): void {
  haptics.selection()
  days.value = [...next]
}

function toggleDay(day: number): void {
  haptics.selection()
  const next = days.value.includes(day) ? days.value.filter((d) => d !== day) : [...days.value, day].sort((a, b) => a - b)
  // At least one day: the last one stays on.
  if (next.length) days.value = next
}

function pickHabit(id: number | null): void {
  haptics.selection()
  habitId.value = id
}

const canSave = computed(() => title.value.trim() !== '' && !sameTime.value && days.value.length > 0)

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    await calendar.saveBlock(
      { title: title.value.trim(), kind: kind.value, start_time: start.value, end_time: end.value, days: days.value, remind: remind.value, habit_id: habitId.value },
      props.block?.id,
    )
    haptics.notify('success')
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('routine.failed')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  const block = props.block
  if (!block || deleting.value) return
  deleting.value = true
  error.value = null
  try {
    await calendar.removeBlock(block.id)
    open.value = false
    const restore = async (): Promise<void> => {
      await calendar.restoreBlock(block.id)
    }
    showToast({ message: t('routine.deleted'), detail: block.title, action: { label: t('routine.undo'), run: restore } })
  } catch {
    haptics.notify('error')
    error.value = t('routine.failed')
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <UiSheet v-model:open="open" :title="block ? $t('routine.edit') : $t('routine.new')">
    <div class="flex flex-col gap-5 pt-1">
      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('routine.kind') }}</h3>
        <div class="flex flex-wrap gap-2" role="radiogroup" :aria-label="$t('routine.kind')">
          <button
            v-for="k in ROUTINE_KINDS"
            :key="k"
            type="button"
            role="radio"
            class="chip !gap-1.5 !pl-3"
            :class="{ 'chip-on': kind === k }"
            :aria-checked="kind === k"
            @click="pickKind(k)"
          >
            <component :is="routineLook(k).icon" class="size-4" :stroke-width="2" :style="kind === k ? undefined : { color: routineColor(k) }" />
            {{ $t(`routine.kinds.${k}`) }}
          </button>
        </div>
      </section>

      <input v-model="title" maxlength="60" enterkeyhint="done" :placeholder="$t('routine.titlePlaceholder')" class="field text-lg font-semibold" @keydown.enter.prevent="save" />

      <section class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-sm text-ink-2">{{ $t('routine.from') }}</span>
          <label class="chip">
            <input type="time" :value="start" class="num bg-transparent outline-none" :aria-label="$t('routine.from')" @change="setTime($event, 'start')" />
          </label>
          <span class="ml-1 text-sm text-ink-2">{{ $t('routine.to') }}</span>
          <label class="chip">
            <input type="time" :value="end" class="num bg-transparent outline-none" :aria-label="$t('routine.to')" @change="setTime($event, 'end')" />
          </label>
        </div>
        <p v-if="sameTime" class="px-1 text-caption text-danger" role="alert">{{ $t('routine.sameTime') }}</p>
        <p v-else-if="overnight" class="px-1 text-caption text-ink-3">{{ $t('routine.overnight', { time: end }) }}</p>
      </section>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('routine.days') }}</h3>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="preset in presets"
            :key="preset.label"
            type="button"
            class="chip"
            :class="{ 'chip-on': sameDays(days, preset.days) }"
            :aria-pressed="sameDays(days, preset.days)"
            @click="pickDays(preset.days)"
          >
            {{ preset.label }}
          </button>
        </div>
        <div class="grid grid-cols-7 gap-1">
          <button
            v-for="(name, index) in weekdays"
            :key="name"
            type="button"
            class="press rounded-control py-2.5 text-sm font-medium"
            :class="days.includes(index + 1) ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2'"
            :aria-pressed="days.includes(index + 1)"
            @click="toggleDay(index + 1)"
          >
            {{ name }}
          </button>
        </div>
      </section>

      <div class="group">
        <UiSwitch v-model="remind" :title="$t('routine.remind')" :hint="$t('routine.remindHint')" />
      </div>

      <section v-if="habits.active.length" class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('routine.habit') }}</h3>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="chip" :class="{ 'chip-on': habitId === null }" :aria-pressed="habitId === null" @click="pickHabit(null)">
            {{ $t('routine.habitNone') }}
          </button>
          <button
            v-for="habit in habits.active"
            :key="habit.id"
            type="button"
            class="chip !gap-1.5 !pl-3"
            :class="{ 'chip-on': habitId === habit.id }"
            :aria-pressed="habitId === habit.id"
            @click="pickHabit(habit.id)"
          >
            <component
              :is="habitIcon(habit.icon)"
              class="size-4"
              :stroke-width="2"
              :style="habitId === habit.id ? undefined : { color: CATEGORY_COLORS[habit.color] ?? CATEGORY_COLORS.green }"
            />
            {{ habit.name }}
          </button>
        </div>
        <p class="px-1 text-caption text-ink-3">{{ $t('routine.habitHint') }}</p>
      </section>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex gap-2">
        <UiButton v-if="block" variant="danger-soft" class="!w-12 !px-0" :loading="deleting" :aria-label="$t('routine.delete')" @click="remove">
          <Trash2 class="size-5" />
        </UiButton>
        <UiButton class="flex-1" :disabled="!canSave" :loading="saving" @click="save">{{ $t('routine.save') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
