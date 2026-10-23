<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check, CircleCheck, Flag, Handshake } from 'lucide-vue-next'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { eventFill } from '@/lib/calendar'
import type { CalendarItem } from '@/stores/calendar'

/**
 * One thing of a day as a one-line chip: in the all-day area, as a bar across a week row, or in a
 * month cell. The colour says what it is (an event its own colour, a task blue, money green, a goal
 * plum). `md` is 24px with icons and a payment's amount; `sm` 20px; `xs` the 16px line of a phone's
 * month cell, cut off without an ellipsis. `cutStart`/`cutEnd`: a bar that goes on past the row.
 */
const props = withDefaults(
  defineProps<{ item: CalendarItem; size?: 'xs' | 'sm' | 'md'; cutStart?: boolean; cutEnd?: boolean; as?: 'button' | 'span' }>(),
  { size: 'md', cutStart: false, cutEnd: false, as: 'button' },
)

const { t } = useI18n()

const text = computed(() => {
  const item = props.item
  if (item.type === 'income') return t('calendar.income', { name: item.title })
  if (item.type === 'goal') return t('calendar.deadline', { title: item.title })
  if (item.type === 'lent') return t('calendar.lentDue', { name: item.title })
  return item.title
})

const look = computed(() => {
  const item = props.item
  switch (item.type) {
    case 'event':
      return 'text-white'
    case 'task':
      return item.done ? 'bg-task-soft text-ink-3' : item.projected ? 'bg-task-soft text-ink-2' : 'bg-task-soft text-task'
    case 'payment':
      return item.paid ? 'bg-surface-2 text-ink-3' : item.overdue ? 'bg-danger-soft text-danger' : item.projected ? 'bg-surface-2 text-ink-2' : 'bg-surface-2 text-ink'
    case 'income':
      return 'bg-money-soft text-money'
    case 'goal':
      return 'bg-goal-soft text-goal'
    case 'lent':
      return item.overdue ? 'bg-surface-2 text-danger' : 'bg-surface-2 text-ink'
    case 'habit':
      return 'bg-habit-soft text-habit'
  }
  return ''
})

const sizes = {
  xs: 'h-4 px-1 text-micro gap-0.5',
  sm: 'h-5 px-1.5 text-micro gap-1',
  md: 'h-6 px-2 text-caption gap-1.5',
} as const

const icons = computed(() => props.size !== 'xs')
const hasAmount = computed(() => props.size === 'md' && (props.item.type === 'payment' || props.item.type === 'lent'))
</script>

<template>
  <component
    :is="as"
    :type="as === 'button' ? 'button' : undefined"
    class="chip-line flex min-w-0 items-center overflow-hidden text-left font-medium whitespace-nowrap"
    :data-amount="hasAmount || undefined"
    :class="[
      sizes[size],
      look,
      cutStart ? 'rounded-l-none' : size === 'xs' ? 'rounded-l-[0.25rem]' : 'rounded-l-[0.375rem]',
      cutEnd ? 'rounded-r-none' : size === 'xs' ? 'rounded-r-[0.25rem]' : 'rounded-r-[0.375rem]',
      { 'press-soft-chip': as === 'button' },
    ]"
    :style="item.type === 'event' ? { backgroundColor: eventFill(item.color) } : undefined"
    :title="text"
  >
    <template v-if="icons">
      <template v-if="item.type === 'task' && !item.projected">
        <CircleCheck v-if="item.done" class="size-3 shrink-0" :stroke-width="2.4" aria-hidden="true" />
        <span v-else class="size-2.5 shrink-0 rounded-full border-[1.5px] border-current" aria-hidden="true" />
      </template>
      <Check v-else-if="item.type === 'payment' && item.paid" class="size-3 shrink-0" :stroke-width="2.6" aria-hidden="true" />
      <Flag v-else-if="item.type === 'goal'" class="size-3 shrink-0" :stroke-width="2.4" aria-hidden="true" />
      <Handshake v-else-if="item.type === 'lent'" class="size-3.5 shrink-0" :stroke-width="2.2" aria-hidden="true" />
    </template>
    <span class="min-w-0 flex-1" :class="[size === 'xs' ? 'overflow-clip' : 'truncate', { 'line-through': item.type === 'task' && item.done }]">{{ text }}</span>
    <span v-if="hasAmount && (item.type === 'payment' || item.type === 'lent')" class="amount shrink-0 font-semibold">
      <MoneyAmount :amount="item.amount" :currency="item.currency" />
    </span>
  </component>
</template>

<style scoped>
/* A payment's amount needs the chip to be wide enough for its name too (a week's day column is not). */
[data-amount] {
  container-type: inline-size;
}

.amount {
  display: none;
}

@container (min-width: 12rem) {
  .amount {
    display: inline;
  }
}

/* A tap darkens the chip a little, whatever its colour. */
.press-soft-chip {
  transition: filter var(--dur-fast) var(--ease-standard);
}

@media (hover: hover) {
  .press-soft-chip:hover {
    filter: brightness(0.96);
  }
}

.press-soft-chip:active {
  filter: brightness(0.92);
}
</style>
