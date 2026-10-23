<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Clock } from 'lucide-vue-next'
import UiRow from '@/components/ui/UiRow.vue'
import { eventFill, toMinutes } from '@/lib/calendar'
import { isoDate, isoTime } from '@/lib/dates'
import type { EventItem, RoutineOccurrence } from '@/stores/calendar'
import { usePlan } from '@/stores/plan'
import { eventsAhead, nowAndNext } from './routine'
import { routineColor, routineLook } from './routineLook'

/**
 * «План дня» on «Сегодня»: the routine block on now and the next one, and up to three events still
 * ahead today, from `plan.today` (it never loads the calendar). Without any routine it offers to set
 * one up; with a routine but nothing on, next or ahead, it is not shown at all.
 */
const plan = usePlan()
const { t } = useI18n()

const calendarLink = { name: 'calendar', query: { view: 'day' } }

// Now and next move with the clock: recomputed on every new minute.
const nowMin = ref(toMinutes(isoTime()))
let timer = 0
function tick(): void {
  nowMin.value = toMinutes(isoTime())
  timer = window.setTimeout(tick, 60_000 - (Date.now() % 60_000) + 50)
}
onMounted(tick)
onBeforeUnmount(() => window.clearTimeout(timer))

const day = computed(() => plan.today?.date ?? isoDate())
const routine = computed(() => nowAndNext(plan.today?.plan?.routine ?? [], day.value, nowMin.value))
const events = computed(() => eventsAhead(plan.today?.plan?.events ?? [], day.value, nowMin.value))
const noRoutine = computed(() => plan.today?.routine_blocks === 0)
const shown = computed(() => !!plan.today && (noRoutine.value || !!routine.value.current || !!routine.value.next || events.value.length > 0))

const lines = computed(() => {
  const { current, next } = routine.value
  const list: { key: string; label: string; block: RoutineOccurrence; time: string }[] = []
  if (current) list.push({ key: 'now', label: t('today.now'), block: current, time: t('today.until', { time: current.end }) })
  if (next) list.push({ key: 'next', label: t('today.next'), block: next, time: t('today.at', { time: next.start }) })
  return list
})

function timeLabel(event: EventItem): string {
  return event.all_day || !event.start ? t('today.allDay') : `${event.start}–${event.end}`
}
</script>

<template>
  <section v-if="shown" class="flex flex-col gap-3">
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="section-title">{{ $t('today.plan') }}</h2>
      <RouterLink :to="calendarLink" class="section-link">{{ $t('today.calendar') }}</RouterLink>
    </div>

    <div v-if="lines.length" class="flex flex-col gap-2">
      <p v-for="line in lines" :key="line.key" class="flex items-center gap-2.5 text-[0.9375rem]">
        <span class="disc grid size-6 shrink-0 place-items-center rounded-full text-white" :style="{ backgroundColor: routineColor(line.block.kind) }" aria-hidden="true">
          <component :is="routineLook(line.block.kind).icon" class="size-3.5" :stroke-width="2.2" />
        </span>
        <span class="min-w-0 truncate">
          <span class="text-ink-3">{{ line.label }}</span> · <span class="font-medium">{{ line.block.title }}</span> ·
          <span class="num text-ink-2">{{ line.time }}</span>
        </span>
      </p>
    </div>

    <div v-if="events.length || noRoutine" class="group">
      <RouterLink v-for="event in events" :key="event.key" :to="calendarLink" class="press-soft flex min-h-13 items-center gap-3 px-4 py-3">
        <span class="num w-[5.75rem] shrink-0 text-caption text-ink-2">{{ timeLabel(event) }}</span>
        <span class="h-5 w-1 shrink-0 rounded-full" :style="{ backgroundColor: eventFill(event.color) }" aria-hidden="true" />
        <span class="min-w-0 flex-1 truncate font-medium">{{ event.title }}</span>
      </RouterLink>
      <UiRow v-if="noRoutine" :to="{ name: 'routine' }" :title="$t('today.setupRoutine')" tone="muted">
        <template #leading>
          <span class="grid size-[22px] place-items-center text-ink-3"><Clock class="size-5" :stroke-width="2" /></span>
        </template>
      </UiRow>
    </div>
  </section>
</template>
