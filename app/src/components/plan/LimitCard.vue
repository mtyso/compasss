<script setup lang="ts">
import { computed } from 'vue'
import CompassMark from '@/components/ui/CompassMark.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { formatDayMonth, formatMoney } from '@/lib/format'
import type { DailyLimit } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

const props = defineProps<{ limit: DailyLimit; incomeName?: string | null }>()
const settings = useSettings()

const over = computed(() => props.limit.left_today_uzs < 0)
const share = computed(() =>
  props.limit.limit_uzs > 0 ? props.limit.spent_today_uzs / props.limit.limit_uzs : props.limit.spent_today_uzs > 0 ? 2 : 0,
)
// The needle stands upright while the day is on course, leans as the limit is used up and swings
// off course past it.
const bearing = computed(() => (share.value <= 1 ? share.value * 50 : 50 + Math.min(share.value - 1, 1) * 85))
const money = (value: number) => formatMoney(value, 'UZS', settings.locale)
</script>

<template>
  <article class="hero relative flex flex-col gap-4 overflow-hidden p-5">
    <div class="flex items-start gap-3">
      <div class="flex min-w-0 flex-1 flex-col gap-2">
        <span class="text-sm font-medium text-on-hero-2">{{ over ? $t('today.over') : $t('today.limit') }}</span>
        <MoneyAmount
          :amount="Math.abs(limit.left_today_uzs)"
          animate
          class="font-display text-amount font-bold"
          :class="over ? 'text-hero-danger' : 'text-on-hero'"
        />
      </div>
      <CompassMark :size="52" tone="on-hero" :angle="bearing" :alert="over" swing class="-mt-0.5 -mr-1" />
    </div>

    <div class="flex flex-col gap-2">
      <div class="h-1.5 overflow-hidden rounded-full bg-on-hero/12">
        <div
          class="limit-bar h-full w-full origin-left rounded-full"
          :class="over ? 'bg-hero-danger' : 'bg-hero-accent'"
          :style="{ transform: `scaleX(${Math.min(1, share)})` }"
        />
      </div>
      <div class="flex flex-col gap-0.5 text-caption text-on-hero-2">
        <span class="num">{{ $t('today.perDay', { amount: money(limit.limit_uzs) }) }}</span>
        <span>
          <template v-if="limit.estimated_end">{{ $t('today.untilMonthEnd', limit.days_left) }}</template>
          <template v-else>{{ $t('today.untilIncome', { name: incomeName ?? $t('today.income'), date: formatDayMonth(limit.until, settings.locale) }) }}, {{ $t('today.daysLeft', limit.days_left) }}</template>
        </span>
      </div>
    </div>

    <dl class="grid grid-cols-2 gap-3 border-t border-on-hero/10 pt-4">
      <div class="flex min-w-0 flex-col gap-0.5">
        <dt class="text-caption text-on-hero-2">{{ $t('today.free') }}</dt>
        <dd class="truncate font-semibold" :class="limit.free_uzs < 0 ? 'text-hero-danger' : 'text-on-hero'">
          <MoneyAmount :amount="limit.free_uzs" animate />
        </dd>
      </div>
      <div class="flex min-w-0 flex-col gap-0.5">
        <dt class="text-caption text-on-hero-2">{{ $t('today.setAside') }}</dt>
        <dd class="truncate font-semibold text-on-hero">
          <MoneyAmount :amount="limit.upcoming_payments_uzs + limit.min_balance_uzs" animate />
        </dd>
      </div>
    </dl>

    <RouterLink
      v-if="limit.estimated_end"
      :to="{ name: 'income' }"
      class="press -mx-1 -mb-1 rounded-control bg-on-hero/8 px-3 py-2.5 text-sm font-medium text-hero-accent"
    >
      {{ $t('today.addIncome') }}
    </RouterLink>
  </article>
</template>

<style scoped>
.limit-bar {
  transition:
    transform 800ms var(--ease-spring),
    background-color var(--dur-base) var(--ease-standard);
}

@starting-style {
  .limit-bar {
    transform: scaleX(0);
  }
}
</style>
