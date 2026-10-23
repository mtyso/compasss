<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Trash2 } from 'lucide-vue-next'
import RepeatPicker from '@/components/things/RepeatPicker.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { addMinutes, DAY_MINUTES, EVENT_COLORS, eventFill, nextHourDraft, toMinutes } from '@/lib/calendar'
import { isoDate, isoTime, shiftDate } from '@/lib/dates'
import { daysBetween } from '@/lib/goals'
import { haptics } from '@/lib/haptics'
import { useCalendar, type CalendarEvent, type EventInput, type Scope } from '@/stores/calendar'
import type { RepeatInput } from '@/stores/tasks'
import ScopeSheet from './ScopeSheet.vue'

/**
 * Create or edit an event. `event` edits one (for a repeating series, `occurrence` is the occurrence
 * tapped: the sheet shows its dates, and a change asks which occurrences it is for); `draft` pre-fills
 * a new one: with `start_time` a timed event (a time tapped or dragged on the grid), without one an
 * all-day event on `starts_on`. No draft at all is the next whole hour today.
 */
const props = defineProps<{
  event: CalendarEvent | null
  occurrence?: { date: string; end_date: string } | null
  draft?: Partial<EventInput> | null
}>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ saved: [event: CalendarEvent]; deleted: [] }>()

const calendar = useCalendar()
const { t } = useI18n()

const title = ref('')
const color = ref('indigo')
const showColors = ref(false)
const allDay = ref(false)
const startDate = ref('')
const startTime = ref('09:00')
/** The end date the sheet shows; only read for an event of several days (`multiDay`). */
const endDate = ref('')
const endTime = ref('10:00')
/** The end has its own date chip: the event was opened that way or «Несколько дней» was tapped. */
const multiDay = ref(false)
const repeat = ref<RepeatInput | null>(null)
const until = ref<string | null>(null)
const remind = ref<number | null>(null)
const notes = ref('')
const saving = ref(false)
const deleting = ref(false)
const error = ref<string | null>(null)
const titleInput = ref<HTMLInputElement | null>(null)

/** An all-day event opened without times takes the next hour (or 09:00) only when it becomes timed. */
let timesKnown = false
/** The fields as the sheet opened, so a change sends only what moved (docs/PLAN.md, calendar 2.7). */
let initial: EventInput = {}

/** A series (not an exception of one) opened at one of its occurrences: changes ask for a scope. */
const occurrenceDate = computed(() => {
  const event = props.event
  return event && event.repeat && event.series_id === null && props.occurrence ? props.occurrence.date : null
})

/** The day a timed event shown on one day ends: the next one when the end is before the start (23:00–01:00). */
function lastDayFor(date: string, start: string, end: string): string {
  return end < start ? shiftDate(date, 1) : date
}

watch(open, (value) => {
  if (!value) return
  const event = props.event
  const draft = props.draft
  const today = isoDate()
  error.value = null
  showColors.value = false
  title.value = event?.title ?? draft?.title ?? ''
  notes.value = event?.notes ?? draft?.notes ?? ''
  color.value = event?.color ?? draft?.color ?? 'indigo'

  let startsOn: string
  let endsOn: string
  let times: { start: string; end: string } | null
  if (event) {
    startsOn = occurrenceDate.value ?? event.starts_on
    endsOn = shiftDate(startsOn, daysBetween(event.starts_on, event.ends_on))
    times = event.start_time && event.end_time ? { start: event.start_time, end: event.end_time } : null
  } else if (draft) {
    startsOn = draft.starts_on ?? today
    endsOn = draft.ends_on ?? startsOn
    times = draft.start_time && draft.end_time ? { start: draft.start_time, end: draft.end_time } : null
  } else {
    const next = nextHourDraft(today, today, toMinutes(isoTime()))
    startsOn = next.starts_on!
    endsOn = next.ends_on!
    times = { start: next.start_time!, end: next.end_time! }
  }
  allDay.value = !times
  timesKnown = !!times
  startDate.value = startsOn
  endDate.value = endsOn
  startTime.value = times?.start ?? '09:00'
  endTime.value = times?.end ?? '10:00'
  multiDay.value = endsOn !== (times ? lastDayFor(startsOn, times.start, times.end) : startsOn)

  const rule = event ? event.repeat : (draft?.repeat ?? null)
  repeat.value = rule && cleanRule(rule)
  until.value = event?.repeat_until ?? draft?.repeat_until ?? null
  remind.value = event ? event.remind_before : (draft?.remind_before ?? null)
  initial = input()
})

function onOpened(): void {
  if (!props.event) nextTick(() => titleInput.value?.focus({ preventScroll: true }))
}

function cleanRule(rule: RepeatInput): RepeatInput {
  return { unit: rule.unit, every: rule.every, ...(rule.unit === 'week' && rule.days?.length ? { days: [...rule.days].sort((a, b) => a - b) } : {}) }
}

/* ---------- When ---------- */

/** The day it ends: the end date chip of an event of several days, else worked out from the times. */
const lastDay = computed(() => {
  if (multiDay.value) return endDate.value
  return allDay.value ? startDate.value : lastDayFor(startDate.value, startTime.value, endTime.value)
})
const endsNextDay = computed(() => !allDay.value && !multiDay.value && endTime.value < startTime.value && endTime.value !== '00:00')
const endBeforeStart = computed(() =>
  allDay.value ? lastDay.value < startDate.value : `${lastDay.value} ${endTime.value}` <= `${startDate.value} ${startTime.value}`,
)

/** From the start to the end, in minutes (whole days for an all-day event). */
function duration(): number {
  const days = daysBetween(startDate.value, lastDay.value)
  return days * DAY_MINUTES + (allDay.value ? 0 : toMinutes(endTime.value) - toMinutes(startTime.value))
}

/** A new start keeps the length: the end moves with it. */
function setStart(date: string, time: string): void {
  const length = duration()
  startDate.value = date
  startTime.value = time
  if (!allDay.value) timesKnown = true
  if (length <= 0) return
  const end = addMinutes(date, allDay.value ? '00:00' : time, length)
  endDate.value = end.date
  if (!allDay.value) endTime.value = end.time
}

function setEndTime(time: string): void {
  endTime.value = time
  timesKnown = true
}

/** A field emptied with the browser's clear button goes back to what it was. */
function valueOf(event: Event, previous: string): string | null {
  const field = event.target as HTMLInputElement
  if (field.value) return field.value
  field.value = previous
  return null
}

function onStartDate(event: Event): void {
  const date = valueOf(event, startDate.value)
  if (date) setStart(date, startTime.value)
}

function onStartTime(event: Event): void {
  const time = valueOf(event, startTime.value)
  if (time) setStart(startDate.value, time)
}

function onEndDate(event: Event): void {
  const date = valueOf(event, endDate.value)
  if (date) endDate.value = date
}

function onEndTime(event: Event): void {
  const time = valueOf(event, endTime.value)
  if (time) setEndTime(time)
}

function showMoreDays(): void {
  haptics.selection()
  endDate.value = allDay.value ? shiftDate(startDate.value, 1) : lastDay.value
  multiDay.value = true
}

function setAllDay(on: boolean): void {
  if (on) {
    // Several days keep their dates; one day, even past midnight, stays that day.
    if (multiDay.value) endDate.value = lastDay.value
    // An all-day event reminds at 09:00 of its day or the day before.
    if (remind.value !== null && remind.value !== 0 && remind.value !== DAY_MINUTES) remind.value = remind.value >= DAY_MINUTES ? DAY_MINUTES : 0
  } else if (!timesKnown) {
    const next = nextHourDraft(startDate.value, isoDate(), toMinutes(isoTime()))
    startTime.value = next.start_time!
    endTime.value = next.end_time!
    timesKnown = true
  }
  allDay.value = on
}

/* ---------- Colour, reminder ---------- */

function toggleColors(): void {
  haptics.selection()
  showColors.value = !showColors.value
}

function pickColor(key: string): void {
  haptics.selection()
  color.value = key
  showColors.value = false
}

const reminders = computed(() =>
  allDay.value
    ? ([null, 0, 1440] as const).map((value) => ({ value, label: t(`events.remindersAllDay.${value === null ? 'none' : `m${value}`}`) }))
    : ([null, 0, 10, 30, 60, 1440] as const).map((value) => ({ value, label: t(`events.reminders.${value === null ? 'none' : `m${value}`}`) })),
)

function pickRemind(value: number | null): void {
  haptics.selection()
  remind.value = value
}

/* ---------- Save and delete ---------- */

function input(): EventInput {
  const timed = !allDay.value
  return {
    title: title.value.trim(),
    notes: notes.value.trim() || null,
    starts_on: startDate.value,
    ends_on: lastDay.value,
    start_time: timed ? startTime.value : null,
    end_time: timed ? endTime.value : null,
    color: color.value,
    repeat: repeat.value && cleanRule(repeat.value),
    repeat_until: repeat.value ? until.value : null,
    remind_before: remind.value,
  }
}

const WHEN = ['starts_on', 'ends_on', 'start_time', 'end_time'] as const
const OTHER = ['title', 'notes', 'color', 'repeat', 'repeat_until', 'remind_before'] as const

/**
 * What changed since the sheet opened. The four date and time fields go together; the rule only when
 * it changed, so that moving a weekly event's day moves its weekdays along (the server does that).
 */
function changes(): EventInput {
  const now = input()
  const differs = (key: keyof EventInput) => JSON.stringify(now[key] ?? null) !== JSON.stringify(initial[key] ?? null)
  const keys: (keyof EventInput)[] = [...(WHEN.some(differs) ? WHEN : []), ...OTHER.filter(differs)]
  return Object.fromEntries(keys.map((key) => [key, now[key]])) as EventInput
}

const canSave = computed(() => title.value.trim() !== '' && !endBeforeStart.value)

const scopeOpen = ref(false)
const scopeMode = ref<'edit' | 'delete'>('edit')
const scopeAllowOne = ref(true)
let pending: EventInput = {}

/** «Это и следующие» is the whole series from its first occurrence on. */
const allowFollowing = computed(() => occurrenceDate.value !== null && occurrenceDate.value !== props.event?.starts_on)

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  if (!props.event) return commit(input())
  const body = changes()
  if (!Object.keys(body).length) {
    open.value = false
    return
  }
  if (occurrenceDate.value === null) return commit(body)
  // A rule or an end of the series is never one occurrence's own.
  scopeAllowOne.value = !('repeat' in body || 'repeat_until' in body)
  if (!scopeAllowOne.value && !allowFollowing.value) return commit(body, 'all')
  pending = body
  scopeMode.value = 'edit'
  scopeOpen.value = true
}

async function commit(body: EventInput, scope?: Scope): Promise<void> {
  saving.value = true
  error.value = null
  try {
    const date = occurrenceDate.value ?? undefined
    const event = await calendar.saveEvent(body, props.event?.id, date ? (scope ?? 'all') : undefined, date)
    haptics.notify('success')
    open.value = false
    emit('saved', event)
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('events.failed')
  } finally {
    saving.value = false
  }
}

function askDelete(): void {
  if (!props.event || deleting.value) return
  if (occurrenceDate.value === null) return void remove('all')
  scopeAllowOne.value = true
  scopeMode.value = 'delete'
  scopeOpen.value = true
}

async function remove(scope: Scope): Promise<void> {
  const event = props.event
  if (!event) return
  deleting.value = true
  error.value = null
  try {
    const undo = await calendar.removeEvent(event.id, scope, scope === 'all' ? undefined : (occurrenceDate.value ?? undefined))
    open.value = false
    showToast({ message: t('events.deleted'), detail: event.title, action: { label: t('events.undo'), run: () => calendar.undo(undo) } })
    emit('deleted')
  } catch {
    haptics.notify('error')
    error.value = t('events.failed')
  } finally {
    deleting.value = false
  }
}

function onScope(scope: Scope): void {
  if (scopeMode.value === 'edit') void commit(pending, scope)
  else void remove(scope)
}
</script>

<template>
  <UiSheet v-model:open="open" :title="event ? $t('events.edit') : $t('events.new')" @opened="onOpened">
    <div class="flex flex-col gap-5 pt-1">
      <div class="flex flex-col gap-2">
        <div class="relative">
          <input
            ref="titleInput"
            v-model="title"
            maxlength="200"
            enterkeyhint="done"
            :placeholder="$t('events.titlePlaceholder')"
            class="field w-full pr-14 text-lg font-semibold"
            @keydown.enter.prevent="save"
          />
          <button
            type="button"
            class="press absolute inset-y-0 right-0 grid w-13 place-items-center rounded-control"
            :aria-label="$t('events.color')"
            :aria-expanded="showColors"
            @click="toggleColors"
          >
            <span class="size-6 rounded-full" :style="{ backgroundColor: eventFill(color) }" />
          </button>
        </div>
        <Transition name="fade">
          <div v-if="showColors" class="grid grid-cols-10 gap-1.5 py-1" role="radiogroup" :aria-label="$t('events.color')">
            <button
              v-for="key in EVENT_COLORS"
              :key="key"
              type="button"
              role="radio"
              class="swatch press aspect-square rounded-full"
              :class="{ on: color === key }"
              :style="{ backgroundColor: eventFill(key) }"
              :aria-label="key"
              :aria-checked="color === key"
              @click="pickColor(key)"
            />
          </div>
        </Transition>
      </div>

      <div class="group">
        <UiSwitch :model-value="allDay" :title="$t('events.allDay')" @update:model-value="setAllDay" />
      </div>

      <section class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2">
        <span class="field-label">{{ $t('events.starts') }}</span>
        <div class="flex flex-wrap gap-2">
          <input type="date" :value="startDate" class="chip" :aria-label="$t('events.starts')" @change="onStartDate" />
          <label v-if="!allDay" class="chip">
            <input type="time" :value="startTime" class="num bg-transparent outline-none" :aria-label="$t('events.starts')" @change="onStartTime" />
          </label>
        </div>

        <span class="field-label">{{ $t('events.ends') }}</span>
        <div class="flex flex-wrap gap-2">
          <input v-if="multiDay" type="date" :value="endDate" :min="startDate" class="chip" :aria-label="$t('events.ends')" @change="onEndDate" />
          <label v-if="!allDay" class="chip">
            <input type="time" :value="endTime" class="num bg-transparent outline-none" :aria-label="$t('events.ends')" @change="onEndTime" />
          </label>
          <button v-if="!multiDay" type="button" class="chip" @click="showMoreDays">{{ $t('events.moreDays') }}</button>
        </div>

        <p v-if="endBeforeStart" class="col-start-2 text-caption text-danger" role="alert">{{ $t('events.endBeforeStart') }}</p>
        <p v-else-if="endsNextDay" class="col-start-2 text-caption text-ink-3">{{ $t('events.endNextDay') }}</p>
      </section>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('tasks.repeat') }}</h3>
        <RepeatPicker v-model="repeat" :start-date="startDate" />
        <Transition name="fade">
          <div v-if="repeat" class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-ink-2">{{ $t('events.until') }}</span>
            <button type="button" class="chip" :class="{ 'chip-on': !until }" :aria-pressed="!until" @click="haptics.selection(); until = null">
              {{ $t('events.untilNever') }}
            </button>
            <input
              type="date"
              :value="until ?? ''"
              :min="startDate"
              class="chip"
              :class="{ 'chip-on': until }"
              :aria-label="$t('events.until')"
              @change="until = ($event.target as HTMLInputElement).value || null"
            />
          </div>
        </Transition>
      </section>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('events.remind') }}</h3>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="option in reminders"
            :key="String(option.value)"
            type="button"
            class="chip"
            :class="{ 'chip-on': remind === option.value }"
            :aria-pressed="remind === option.value"
            @click="pickRemind(option.value)"
          >
            {{ option.label }}
          </button>
        </div>
        <p class="px-1 text-caption text-ink-3">{{ $t('events.remindHint') }}</p>
      </section>

      <textarea v-model="notes" rows="3" maxlength="5000" :placeholder="$t('events.notesPlaceholder')" class="field resize-none text-body" />
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex gap-2">
        <UiButton v-if="event" variant="danger-soft" class="!w-12 !px-0" :loading="deleting" :aria-label="$t('events.delete')" @click="askDelete">
          <Trash2 class="size-5" />
        </UiButton>
        <UiButton class="flex-1" :disabled="!canSave" :loading="saving" @click="save">{{ $t('events.save') }}</UiButton>
      </div>
    </template>
  </UiSheet>

  <ScopeSheet v-model:open="scopeOpen" :mode="scopeMode" :allow-following="allowFollowing" :allow-one="scopeAllowOne" @pick="onScope" />
</template>

<style scoped>
.swatch {
  box-shadow: 0 0 0 0 var(--c-raised);
  transition:
    transform var(--dur-spring) var(--ease-spring),
    box-shadow var(--dur-fast) var(--ease-standard);
}

.swatch.on {
  box-shadow:
    0 0 0 2px var(--c-raised),
    0 0 0 4px var(--c-ink);
  transform: scale(0.88);
}
</style>
