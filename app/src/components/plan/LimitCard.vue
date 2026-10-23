<script setup lang="ts">
import { computed } from 'vue'
import { ChevronRight } from 'lucide-vue-next'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { formatDayMonth, formatMoney } from '@/lib/format'
import type { DailyLimit } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * Today's safe-to-spend, the first thing on «Сегодня». Set as type, not as a card: the sum is the
 * largest thing on the screen and rolls up like a counter when the screen opens.
 *
 * Under it is the runway to payday: one capsule per day left, today first and widest. Green is
 * money a day still has; today's capsule empties from the right as the day's limit is spent and
 * turns red once it is overspent, and the days after it wait full. So the picture says what the
 * number means: the free money, split evenly between the days until income.
 */
const props = defineProps<{ limit: DailyLimit; incomeName?: string | null }>()
const settings = useSettings()

const over = computed(() => props.limit.left_today_uzs < 0)
const left = computed(() =>
  props.limit.limit_uzs > 0 ? Math.max(0, props.limit.left_today_uzs) / props.limit.limit_uzs : 0,
)
/** Days after today on the runway, drawn as segments of one track. */
const later = computed(() => Math.max(0, Math.min(props.limit.days_left, 62) - 1))
const money = (value: number) => formatMoney(value, 'UZS', settings.locale)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="over ? $t('today.over') : $t('today.limit')">
    <div class="flex flex-col gap-1.5">
      <span class="text-[0.9375rem] font-medium text-ink-2">{{ over ? $t('today.over') : $t('today.limit') }}</span>
      <MoneyAmount
        :amount="Math.abs(limit.left_today_uzs)"
        animate
        intro
        class="limit-sum text-amount font-semibold md:text-[3.25rem]"
        :class="over ? 'text-danger' : 'text-ink'"
      />
    </div>

    <div class="flex flex-col gap-2.5">
      <div class="flex h-2.5 gap-1" aria-hidden="true">
        <div class="today-capsule relative overflow-hidden rounded-full" :class="[over ? 'bg-danger' : 'bg-money/15', later ? 'w-[44%] shrink-0' : 'flex-1']">
          <div v-if="!over" class="limit-bar absolute inset-0 origin-left rounded-full bg-money" :style="{ transform: `scaleX(${left})` }" />
        </div>
        <div v-if="later" class="runway relative flex-1 rounded-full" :style="{ '--days': later }" />
      </div>
      <p class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-caption text-ink-2">
        <span class="num">{{ $t('today.perDay', { amount: money(limit.limit_uzs) }) }}</span>
        <span>
          <template v-if="limit.estimated_end">{{ $t('today.untilMonthEnd', limit.days_left) }}</template>
          <template v-else>{{ $t('today.untilIncome', { name: incomeName ?? $t('today.income'), date: formatDayMonth(limit.until, settings.locale) }) }}, {{ $t('today.daysLeft', limit.days_left) }}</template>
        </span>
      </p>
    </div>

    <dl class="grid grid-cols-2 gap-4 rounded-card bg-surface-2 px-4 py-3">
      <div class="flex min-w-0 flex-col gap-0.5">
        <dt class="text-caption text-ink-3">{{ $t('today.free') }}</dt>
        <dd class="truncate font-semibold" :class="{ 'text-danger': limit.free_uzs < 0 }">
          <MoneyAmount :amount="limit.free_uzs" animate />
        </dd>
      </div>
      <div class="flex min-w-0 flex-col gap-0.5">
        <dt class="text-caption text-ink-3">{{ $t('today.setAside') }}</dt>
        <dd class="truncate font-semibold">
          <MoneyAmount :amount="limit.upcoming_payments_uzs + limit.min_balance_uzs + limit.planned_savings_uzs" animate />
        </dd>
      </div>
    </dl>

    <RouterLink
      v-if="limit.estimated_end"
      :to="{ name: 'income' }"
      class="press -mt-1 flex items-center justify-between gap-2 text-sm font-medium text-money"
    >
      {{ $t('today.addIncome') }}
      <ChevronRight class="size-4 shrink-0" :stroke-width="2.4" />
    </RouterLink>
  </section>
</template>

<style scoped>
.limit-sum {
  letter-spacing: -0.035em;
  line-height: 1;
}

.limit-bar {
  transition: transform 800ms var(--ease-spring);
}

@starting-style {
  .limit-bar {
    transform: scaleX(0);
  }
}

/*
 * The days after today: one green track cut into a segment per day by thin gaps of the page
 * colour, so 2 days and 20 days both read as days, not as a bar of a different length.
 */
.runway {
  background:
    repeating-linear-gradient(
      to right,
      transparent 0 calc(100% / var(--days) - 3px),
      var(--c-bg) calc(100% / var(--days) - 3px) calc(100% / var(--days))
    ),
    color-mix(in srgb, var(--c-money) 42%, transparent);
  background-clip: border-box;
  clip-path: inset(0 3px 0 0 round 999px);
  transform-origin: left;
  transition:
    transform 700ms var(--ease-spring) 120ms,
    opacity 300ms var(--ease-standard) 120ms;
}

@starting-style {
  .runway {
    transform: scaleX(0.2);
    opacity: 0;
  }
}
</style>
