<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { CalendarDays, CircleCheck, Flag, HandCoins, Handshake, Receipt } from 'lucide-vue-next'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import { allDayItems, eventFill, isAllDayEvent, LIST_DAYS, periodTitle } from '@/lib/calendar'
import { shiftDate } from '@/lib/dates'
import type { CalendarItem, Layer } from '@/stores/calendar'
import { useSettings } from '@/stores/settings'

/**
 * Two weeks from the anchor as a list: only the days that have something, each with its heading and
 * its things, the ones without a time first. No habits and no routine: this is what happens.
 */
const props = defineProps<{
  from: string
  today: string
  items: CalendarItem[]
  layers: ReadonlySet<Layer>
  skeleton: boolean
}>()
const emit = defineEmits<{ open: [item: CalendarItem] }>()

const { t } = useI18n()
const settings = useSettings()

/** Things with a time on a day: events from the day they start, tasks with a time. */
function timedOn(item: CalendarItem, day: string): boolean {
  if (item.type === 'event') return item.date === day && !isAllDayEvent(item)
  return item.type === 'task' && !!item.start && item.date === day
}

const groups = computed(() =>
  Array.from({ length: LIST_DAYS }, (_, i) => shiftDate(props.from, i))
    .map((day) => {
      const timed = props.items
        .filter((item) => timedOn(item, day))
        .sort((a, b) => ('start' in a && a.start ? a.start : '').localeCompare('start' in b && b.start ? b.start : ''))
      return { day, items: [...allDayItems(props.items, day, props.layers), ...timed] }
    })
    .filter((group) => group.items.length),
)

function heading(day: string): string {
  if (day === props.today) return t('things.today')
  if (day === shiftDate(props.today, 1)) return t('things.tomorrow')
  if (day === shiftDate(props.today, -1)) return t('things.yesterday')
  return periodTitle('day', day, props.today, settings.locale, t)
}

function time(item: CalendarItem): string {
  if (item.type === 'event' && !item.all_day && item.start && item.end) return `${item.start}–${item.end}`
  if (item.type === 'task' && item.start) return item.start
  return t('calendar.allDay')
}

function title(item: CalendarItem): string {
  if (item.type === 'income') return t('calendar.income', { name: item.title })
  if (item.type === 'goal') return t('calendar.deadline', { title: item.title })
  if (item.type === 'lent') return t('calendar.lentDue', { name: item.title })
  return item.title
}

/** The quiet second line: a state in words, when there is one. */
function state(item: CalendarItem): { text: string; danger?: boolean } | null {
  if (item.type === 'task') {
    if (item.projected) return { text: t('calendar.projected') }
    if (item.overdue && !item.done) return { text: t('tasks.groups.overdue'), danger: true }
  }
  if (item.type === 'payment') {
    if (item.paid) return { text: t('calendar.paid') }
    if (item.projected) return { text: t('calendar.projected') }
  }
  if (item.type === 'event' && item.notes) return { text: item.notes.split('\n')[0]! }
  return null
}

function hasAmount(item: CalendarItem): item is Extract<CalendarItem, { amount: number }> {
  return item.type === 'payment' || item.type === 'income' || item.type === 'lent'
}

function amountTone(item: CalendarItem): string {
  if (item.type === 'income') return 'text-money'
  if (item.type === 'payment') return item.paid ? 'text-ink-3' : item.overdue ? 'text-danger' : 'text-ink-2'
  return item.type === 'lent' && item.overdue ? 'text-danger' : 'text-ink-2'
}
</script>

<template>
  <div class="list-view flex w-full max-w-2xl flex-col gap-1 px-4 pt-2 md:px-0">
    <template v-if="skeleton">
      <div v-for="i in 3" :key="i" class="flex flex-col gap-2 py-3">
        <span class="skeleton h-5 w-32" />
        <span class="skeleton h-12" />
      </div>
    </template>

    <UiEmpty v-else-if="!groups.length" :icon="CalendarDays" :text="$t('calendar.listEmpty')" class="mt-3" />

    <section v-for="group in groups" v-else :key="group.day" class="flex flex-col">
      <h2
        class="sticky z-[5] bg-bg pt-3 pb-1.5 section-title"
        :class="{ '!text-money': group.day === today }"
        :style="{ top: 'var(--cal-head, 0px)' }"
      >
        {{ heading(group.day) }}
      </h2>
      <div class="group">
        <button
          v-for="item in group.items"
          :key="item.key"
          type="button"
          class="press-soft flex min-h-14 w-full items-center gap-3 px-4 py-2.5 text-left"
          @click="emit('open', item)"
        >
          <span class="num w-[5.75rem] shrink-0 text-caption text-ink-2">{{ time(item) }}</span>

          <span class="grid size-5 shrink-0 place-items-center" aria-hidden="true">
            <span v-if="item.type === 'event'" class="size-2.5 rounded-full" :style="{ backgroundColor: eventFill(item.color) }" />
            <template v-else-if="item.type === 'task'">
              <CircleCheck v-if="item.done" class="size-4 text-ink-3" :stroke-width="2.2" />
              <span v-else class="size-3 rounded-full border-2" :class="item.projected ? 'border-ink-3' : 'border-task'" />
            </template>
            <Receipt v-else-if="item.type === 'payment'" class="size-4" :class="item.overdue && !item.paid ? 'text-danger' : 'text-ink-3'" :stroke-width="2" />
            <HandCoins v-else-if="item.type === 'income'" class="size-4 text-money" :stroke-width="2" />
            <Flag v-else-if="item.type === 'goal'" class="size-4 text-goal" :stroke-width="2.2" />
            <Handshake v-else-if="item.type === 'lent'" class="size-4" :class="item.overdue ? 'text-danger' : 'text-ink-3'" :stroke-width="2" />
          </span>

          <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span
              class="truncate font-medium"
              :class="{
                'text-ink-3 line-through': item.type === 'task' && item.done,
                'text-ink-3': item.type === 'payment' && item.paid,
                'text-ink-2': (item.type === 'task' || item.type === 'payment') && item.projected,
              }"
            >
              {{ title(item) }}
            </span>
            <span v-if="hasAmount(item) || state(item)" class="flex min-w-0 items-center gap-1.5 text-caption text-ink-3">
              <MoneyAmount
                v-if="hasAmount(item)"
                :amount="item.amount"
                :currency="item.currency"
                :sign="item.type === 'income' ? 'always' : 'auto'"
                :class="amountTone(item)"
              />
              <span v-if="hasAmount(item) && state(item)" aria-hidden="true">·</span>
              <span v-if="state(item)" class="truncate" :class="{ 'text-danger': state(item)!.danger }">{{ state(item)!.text }}</span>
            </span>
          </span>
        </button>
      </div>
    </section>
  </div>
</template>
