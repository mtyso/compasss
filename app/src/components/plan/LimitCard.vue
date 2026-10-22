<script setup lang="ts">
import { computed } from 'vue'
import { ChevronRight } from 'lucide-vue-next'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import { formatDayMonth, formatMoney } from '@/lib/format'
import type { DailyLimit } from '@/stores/plan'
import { useSettings } from '@/stores/settings'

/**
 * Today's safe-to-spend, the first thing on «Сегодня». Set as type, not as a card: the sum is the
 * largest thing on the screen, the bar under it fills as the day's limit is spent, and the line
 * under the bar says what the limit is measured against.
 */
const props = defineProps<{ limit: DailyLimit; incomeName?: string | null }>()
const settings = useSettings()

const over = computed(() => props.limit.left_today_uzs < 0)
const share = computed(() =>
  props.limit.limit_uzs > 0 ? props.limit.spent_today_uzs / props.limit.limit_uzs : props.limit.spent_today_uzs > 0 ? 1 : 0,
)
const money = (value: number) => formatMoney(value, 'UZS', settings.locale)
</script>

<template>
  <section class="flex flex-col gap-4" :aria-label="over ? $t('today.over') : $t('today.limit')">
    <div class="flex flex-col gap-1.5">
      <span class="text-[0.9375rem] font-medium text-ink-2">{{ over ? $t('today.over') : $t('today.limit') }}</span>
      <MoneyAmount
        :amount="Math.abs(limit.left_today_uzs)"
        animate
        class="limit-sum text-amount font-semibold md:text-[3.25rem]"
        :class="over ? 'text-danger' : 'text-ink'"
      />
    </div>

    <div class="flex flex-col gap-2.5">
      <div class="h-2 overflow-hidden rounded-full bg-ink/[0.07]">
        <div
          class="limit-bar h-full w-full origin-left rounded-full"
          :class="over ? 'bg-danger' : 'bg-money'"
          :style="{ transform: `scaleX(${Math.min(1, share)})` }"
        />
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
